<?php

namespace App\Services\Authentication;

class LoginSiteDomainRoot
{
    public function infer(string $host): ?string
    {
        $labels = explode('.', $host);
        $suffixLength = 1;
        $rules = $this->suffixRules();
        $suffix = '';

        // Follow the longest matching Public Suffix List rule, with exceptions taking precedence.
        foreach (array_reverse($labels) as $index => $label) {
            $wildcard = $suffix !== '' && isset($rules['*.'.$suffix]);
            $suffix = $label.($suffix === '' ? '' : '.'.$suffix);
            if (isset($rules['!'.$suffix])) {
                $suffixLength = $index;
                break;
            }
            if ($wildcard || isset($rules[$suffix])) {
                $suffixLength = $index + 1;
            }
        }

        $labelCount = count($labels);
        if ($labelCount <= $suffixLength) {
            return null;
        }

        return $labelCount === $suffixLength + 1 ? $host : implode('.', array_slice($labels, 1));
    }

    private function suffixRules(): array
    {
        static $rules;

        return $rules ??= array_fill_keys(array_filter(
            file(resource_path('public_suffix_list.dat'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            static fn (string $line): bool => !str_starts_with($line, '//'),
        ), true);
    }
}
