# Portal business translations

When a portal gains a business module, add a translation file named after its
portal code, for example:

~~~
lang/en/finance.php
lang/en/purchase.php
~~~

When a business module uses a key such as `assistant.xxx`, its file must be
`lang/en/assistant.php`; the first segment of the key must match the filename.

All user-visible portal business copy in templates, controllers, services,
models, validation messages, modals, and JavaScript must use that portal's
translation keys. Do not hard-code user-facing strings in business files.
Shared portal strings stay in `lang/en/portal.php`.

