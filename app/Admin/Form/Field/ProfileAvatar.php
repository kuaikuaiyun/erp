<?php

namespace App\Admin\Form\Field;

use Ladmin\Form\Field\Image;

/** Keeps avatar removal on the dedicated profile settings endpoint. */
class ProfileAvatar extends Image
{
    public function render()
    {
        $this->options(['deleteUrl' => admin_url('auth/setting')]);

        return parent::render();
    }
}
