<?php
namespace App\Services;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DingTalkCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use App\Portals\Logistics\StreamedXlsxWriter;

class AccountSpreadsheetService
{
    public const LIMIT = 500;
    public const FIELDS = ['username','name','company_id','company_name','status','employment_status','hired_at','resigned_at','department_codes','department_positions'];

    public function __construct(private DepartmentOrganizationService $organization) {}

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['file'=>__('account_transfer.'.$key)]);
    }

    public function create(array $rows, AdminUser $actor, string $password): array
    {
        abort_unless($actor->isAdministrator(),403,__('organization.authorization.super_admin_only'));
        return DB::connection($actor->getConnectionName())->transaction(function () use ($rows,$actor,$password): array {
            DingTalkCompany::query()->whereIn('id',array_filter(array_column($rows,'company_id')))->orderBy('id')->lockForUpdate()->get();
            $codes=[];
            foreach ($rows as $row) foreach (explode(',',str_replace('，',',',$row['department_codes'])) as $code) $codes[]=trim($code);
            Department::business()->whereIn('slug',$codes)->orderBy('id')->lockForUpdate()->get();
            $checked=$this->validateRows($rows,$actor);
            if ($checked['errors']) throw ValidationException::withMessages(['import'=>array_map(fn ($error)=>__('account_transfer.row_error',['line'=>$error['line'],'error'=>implode(' / ',$error['messages'])]),$checked['errors'])]);
            $ids=[]; $model=config('admin.database.users_model');
            foreach ($checked['rows'] as $row) {
                $account=new $model();
                $account->username=$row['username']; $account->name=$row['name'];
                $account->password=Hash::make($password); $account->company_id=$row['company_id'];
                $account->status=$row['status']; $account->employment_status=$row['employment_status'];
                $account->hired_at=$row['hired_at']; $account->resigned_at=$row['resigned_at'];
                try {
                    $account->save();
                    $this->organization->syncAccountMemberships($account,$row['memberships'],$actor);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['import'=>__('account_transfer.row_error',['line'=>$row['line'],'error'=>implode(' / ',\Illuminate\Support\Arr::flatten($exception->errors()))])]);
                }
                $ids[]=$account->id;
            }
            return ['count'=>count($ids),'account_ids'=>$ids,'message'=>__('account_transfer.created',['count'=>count($ids)])];
        },3);
    }

    public function headers(): array
    {
        return array_map(fn ($key) => __('account_transfer.fields.'.$key), self::FIELDS);
    }

    public function download($query = null)
    {
        $template = $query === null;
        $departments = Department::business()->orderBy('id')->get(['id','slug','name']);
        $companies = DingTalkCompany::query()->where('enabled',true)->orderBy('id')->get(['id','name']);
        $stringCells = fn ($values) => ['cells'=>array_map(fn ($v)=>['value'=>(string)$v,'type'=>'string'], $values)];
        $references = function () use ($departments,$companies,$stringCells) {
            yield $stringCells([__('account_transfer.company_reference'),__('organization.common.id'),__('organization.accounts.name')]);
            foreach ($companies as $c) yield $stringCells(['',(string)$c->id,$c->name]);
            yield $stringCells([__('account_transfer.department_reference'),__('account_transfer.fields.department_codes'),__('organization.accounts.name')]);
            foreach ($departments as $d) yield $stringCells(['',$d->slug,$d->name]);
        };
        $rows = function () use ($query,$stringCells) {
            if (!$query) return;
            foreach ($query->with(['company','departments'])->orderBy('id')->lazy(200) as $account) {
                $memberships = $account->departments->sortBy('id');
                yield $stringCells([$account->username,$account->name,$account->company_id ?? '',$account->company?->name ?? '',(string)$account->status,
                    __('organization.accounts.employment_'.($account->employment_status ?: 'employed')),
                    $account->hired_at?->format('Y-m-d') ?? '',$account->resigned_at?->format('Y-m-d') ?? '',
                    $memberships->pluck('slug')->implode(','),$memberships->map(fn ($d)=>__('organization.positions.'.$d->pivot->position))->implode(',')]);
            }
        };
        $response = app(StreamedXlsxWriter::class)->response($template ? 'account-template.xlsx' : 'accounts.xlsx', __('account_transfer.sheet'),
            array_map(fn ($label)=>['label'=>$label,'width'=>26], $this->headers()), $rows,
            additionalSheets: [
                [__('account_transfer.instructions'),[['label'=>__('account_transfer.instructions'),'width'=>100]], function () use ($stringCells) {
                    foreach (__('account_transfer.notes') as $note) yield $stringCells([$note]);
                }],
                [__('account_transfer.references'),[['label'=>__('account_transfer.references'),'width'=>26],['label'=>__('account_transfer.code'),'width'=>28],['label'=>__('organization.accounts.name'),'width'=>40]],$references],
            ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    public function read(UploadedFile $file): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) $this->fail('format');
        try {
            $size = 0;
            for ($i=0;$i<$zip->numFiles;$i++) $size += (int)($zip->statIndex($i)['size'] ?? 0);
            if ($size > 30*1024*1024 || $zip->numFiles > 256) $this->fail('limit');
        } finally { $zip->close(); }
        try {
            if (IOFactory::identify($file->getRealPath()) !== 'Xlsx') $this->fail('format');
            $reader = IOFactory::createReader('Xlsx');
            $info = $reader->listWorksheetInfo($file->getRealPath());
            if (!$info || $info[0]['totalRows'] > self::LIMIT+1 || $info[0]['totalColumns'] > count(self::FIELDS)) $this->fail('limit');
            $reader->setLoadSheetsOnly($info[0]['worksheetName']);
            $reader->setReadDataOnly(false);
            $reader->setReadFilter(new class implements IReadFilter {
                public function readCell(string $column, int $row, string $worksheetName = ''): bool {
                    return $row <= AccountSpreadsheetService::LIMIT+1 && strlen($column) === 1 && $column <= 'J';
                }
            });
            $book = $reader->load($file->getRealPath());
        } catch (ValidationException $e) { throw $e; }
        catch (\Throwable $e) { report($e); $this->fail('format'); }
        try {
            $sheet = $book->getSheet(0); $rows=[]; $headers=[];
            foreach (range('A','J') as $column) {
                $cell = $sheet->getCell($column.'1');
                if ($cell->getDataType() === DataType::TYPE_FORMULA) $this->fail('formula');
                $headers[] = trim((string)$cell->getValue());
            }
            if ($headers !== $this->headers()) $this->fail('headers');
            for ($line=2;$line<=$sheet->getHighestDataRow();$line++) {
                $row=[];
                foreach (self::FIELDS as $index=>$key) {
                    $cell=$sheet->getCell(chr(65+$index).$line);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) $this->fail('formula');
                    $value=$cell->getValue();
                    if (in_array($key,['hired_at','resigned_at'],true) && is_numeric($value) && Date::isDateTime($cell)) $value=Date::excelToDateTimeObject($value)->format('Y-m-d');
                    $row[$key]=trim((string)$value);
                }
                if (!array_filter($row,fn ($value)=>$value!=='')) continue;
                $row['_line']=$line; $rows[]=$row;
            }
            if (!$rows) $this->fail('empty');
            return $rows;
        } finally { $book->disconnectWorksheets(); }
    }

    public function validateRows(array $rows, AdminUser $actor): array
    {
        $errors=[]; $normalized=[]; $seen=[]; $reserved=[];
        $departments=Department::business()->with('children')->get()->keyBy('slug');
        $companies=DingTalkCompany::withTrashed()->get()->keyBy('id');
        $counts=DB::connection($actor->getConnectionName())->table(config('admin.database.role_users_table'))
            ->selectRaw('role_id, position, COUNT(*) AS total')->groupBy('role_id','position')->get();
        $used=[]; foreach ($counts as $count) $used[$count->role_id][$count->position]=(int)$count->total;
        $positions=['manager'=>Department::POSITION_MANAGER,'deputy'=>Department::POSITION_DEPUTY,'member'=>Department::POSITION_MEMBER];
        foreach (array_values($positions) as $position) $positions[__('organization.positions.'.$position)]=$position;
        $userModel=config('admin.database.users_model');
        foreach ($rows as $row) {
            $line=$row['_line']; $issues=[];
            $row['employment_status']=match ($row['employment_status']) {
                'employed'=> 'employed', 'resigned'=> 'resigned',
                default => $row['employment_status']===__('organization.accounts.employment_employed') ? 'employed'
                    : ($row['employment_status']===__('organization.accounts.employment_resigned') ? 'resigned' : $row['employment_status']),
            };
            $validator=Validator::make($row,['username'=>'required|string|max:190','name'=>'required|string|max:255',
                'status'=>'required|in:0,1','employment_status'=>'required|in:employed,resigned',
                'hired_at'=>'nullable|date_format:Y-m-d','resigned_at'=>'nullable|date_format:Y-m-d','company_id'=>'nullable|integer|min:1'],[],array_combine(self::FIELDS,$this->headers()));
            $issues=$validator->errors()->all();
            $identity=mb_strtolower($row['username']);
            if (isset($seen[$identity]) || $userModel::query()->where('username',$row['username'])->exists()) $issues[]=__('account_transfer.duplicate');
            $seen[$identity]=true;
            $company=$row['company_id']!=='' ? $companies->get((int)$row['company_id']) : null;
            if (($row['company_id']!=='' && (!$company || !$company->enabled || $company->trashed()))
                || ($row['company_name']!=='' && (!$company || $company->name!==$row['company_name']))) $issues[]=__('account_transfer.company_invalid');
            $split=fn ($text)=>array_map('trim',explode(',',str_replace('，',',',$text)));
            $codes=$split($row['department_codes']); $roles=$split($row['department_positions']); $memberships=[]; $pairs=[]; $assistantChildren=0;
            if (count($codes)!==count($roles) || in_array('',$codes,true) || in_array('',$roles,true) || count(array_unique($codes))!==count($codes)) $issues[]=__('account_transfer.pairs');
            else foreach ($codes as $i=>$code) {
                $department=$departments->get($code); $position=$positions[$roles[$i]] ?? null;
                if (!$department || !$position || !$department->allowsPosition($position)) { $issues[]=__('account_transfer.department_invalid',['code'=>$code]); continue; }
                if ($department->parent_id && ($departments->firstWhere('id',$department->parent_id)?->slug)==='dept-000004') $assistantChildren++;
                $key=$department->id.':'.$position;
                $cap=$position===Department::POSITION_MANAGER ? 1 : ($position===Department::POSITION_DEPUTY ? 2 : null);
                if ($cap!==null && (($used[$department->id][$position] ?? 0)+($reserved[$key] ?? 0)) >= $cap) $issues[]=__('account_transfer.capacity',['code'=>$code]);
                $pairs[$key]=($pairs[$key] ?? 0)+1;
                $memberships[]=['department_id'=>$department->id,'position'=>$position];
            }
            if ($assistantChildren>1) $issues[]=__('organization.accounts.assistant_child_department_limit');
            if ($issues) { $errors[]=['line'=>$line,'username'=>$row['username'],'messages'=>array_values(array_unique($issues))]; continue; }
            foreach ($pairs as $key=>$count) $reserved[$key]=($reserved[$key] ?? 0)+$count;
            $normalized[]=array_merge($row, ['line'=>$line,'company_id'=>$company?->id,'status'=>(int)$row['status'],'hired_at'=>$row['hired_at'] ?: null,'resigned_at'=>$row['resigned_at'] ?: null,'memberships'=>$memberships]);
        }
        return ['rows'=>$normalized,'errors'=>$errors];
    }

}
