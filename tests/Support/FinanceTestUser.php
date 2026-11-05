<?php

namespace Tests\Support;

use App\Models\AdminUser;

class FinanceTestUser extends AdminUser
{
    public bool $view = true;

    public bool $manage = true;

    public function can($ability, $arguments = []): bool
    {
        return $ability === 'finance.view' ? $this->view : $this->manage;
    }

    public function isAdministrator(): bool
    {
        return true;
    }
}
