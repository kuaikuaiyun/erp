<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\Client;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BootstrapDingTalkAdministrator extends Command
{
    protected $signature = 'dingtalk:bootstrap-admin {account} {user_id} {--company=} {--yes}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('dingtalk.console.bootstrap_description'));
        $this->getDefinition()->setArguments([
            new InputArgument('account', InputArgument::REQUIRED, __('dingtalk.fields.account_id')),
            new InputArgument('user_id', InputArgument::REQUIRED, __('dingtalk.fields.user_id')),
        ]);
        $this->getDefinition()->setOptions(array_map(
            static fn (InputOption $option): InputOption => $option->getName() === 'yes'
                ? new InputOption('yes', null, InputOption::VALUE_NONE, __('dingtalk.console.yes'))
                : $option,
            $this->getDefinition()->getOptions(),
        ));
    }

    public function handle(Client $client, BindingService $bindings): int
    {
        $account = AdminUser::query()->find($this->argument('account'));
        if (!$account || !$account->isAdministrator() || (isset($account->status) && (int) $account->status !== AdminUser::STATUS_ACTIVE)) {
            $this->error(__('dingtalk.console.admin_required'));
            return self::FAILURE;
        }
        try {
            $companies = app(\App\Services\DingTalk\CompanyService::class);
            $options = $companies->options();
            $id = (int)$this->option('company');
            if (!$id && $options->count() !== 1) throw new HttpException(422, __('dingtalk.company.choose'));
            $company = $companies->enabled($id ?: (int)$options->first()->id);
            $member = $client->forCompany($company)->memberByUserId((string) $this->argument('user_id'));
            if (!$member) {
                $this->error(__('dingtalk.errors.not_member'));
                return self::FAILURE;
            }
            $this->line(__('dingtalk.console.preview', ['account' => $account->username, 'id' => $account->id,
                'name' => $member['name'], 'user_id' => $member['user_id'], 'corp_id' => $member['corp_id']]));
            if (!$this->option('yes') && !$this->confirm(__('dingtalk.console.confirm'), false)) {
                return self::FAILURE;
            }
            $bindings->bind((int) $account->id, $member['user_id'], true, (int)$company->id);
            $this->info(__('dingtalk.console.bound'));
            return self::SUCCESS;
        } catch (HttpException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
