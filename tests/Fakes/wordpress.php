<?php

// Imitações mínimas do WordPress e do WooCommerce: só o que o plugin usa.
// Funções ficam a cargo do Brain Monkey em cada teste.

declare(strict_types=1);

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

class WC_Payment_Gateway
{
    /** @var array<string, string> configurações usadas por init_settings() */
    public static array $savedSettings = [];

    public string $id = '';
    public string $icon = '';
    public bool $has_fields = false;
    public string $method_title = '';
    public string $method_description = '';
    public string $title = '';
    public string $description = '';
    public string $enabled = 'no';
    /** @var list<string> */
    public array $supports = [];
    /** @var array<string, array<string, mixed>> */
    public array $form_fields = [];
    /** @var array<string, string> */
    public array $settings = [];

    public function init_settings(): void
    {
        foreach ($this->form_fields as $key => $field) {
            $this->settings[$key] = (string) (self::$savedSettings[$key] ?? $field['default'] ?? '');
        }
        $this->enabled = $this->settings['enabled'] ?? 'no';
    }

    public function get_option(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function is_available(): bool
    {
        return $this->enabled === 'yes';
    }

    public function get_return_url(?WC_Order $order = null): string
    {
        return 'https://loja.test/obrigado/' . ($order?->get_id() ?? 0);
    }

    public function process_admin_options(): bool
    {
        return true;
    }
}

class WC_Order
{
    public int $id;
    public string $total;
    public string $status = 'pending';
    public string $paymentMethod = 'maximopay_pix';
    public string $transactionId = '';
    public bool $paid = false;
    public string $totalRefunded = '0';
    /** @var array<string, mixed> */
    public array $meta = [];
    /** @var list<string> */
    public array $notes = [];
    /** @var list<object> */
    public array $refunds = [];
    /** @var array<string, string> */
    public array $billing = [
        'first_name' => 'João',
        'last_name' => 'Silva',
        'company' => '',
        'email' => 'joao@email.com',
        'phone' => '(11) 99999-0000',
    ];
    public int $saves = 0;

    public function __construct(int $id = 42, string $total = '15.90')
    {
        $this->id = $id;
        $this->total = $total;
    }

    public function get_id(): int { return $this->id; }
    public function get_total(): string { return $this->total; }
    public function get_order_key(): string { return 'wc_order_abc'; }
    public function get_status(): string { return $this->status; }
    public function get_billing_first_name(): string { return $this->billing['first_name']; }
    public function get_billing_last_name(): string { return $this->billing['last_name']; }
    public function get_billing_company(): string { return $this->billing['company']; }
    public function get_billing_email(): string { return $this->billing['email']; }
    public function get_billing_phone(): string { return $this->billing['phone']; }
    public function get_payment_method(): string { return $this->paymentMethod; }
    public function get_transaction_id(): string { return $this->transactionId; }
    public function set_transaction_id(string $id): void { $this->transactionId = $id; }
    public function is_paid(): bool { return $this->paid; }
    public function get_total_refunded(): string { return $this->totalRefunded; }
    /** @return list<object> */
    public function get_refunds(): array { return $this->refunds; }

    public function get_meta(string $key): mixed
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function save(): int
    {
        $this->saves++;
        return $this->id;
    }

    public function add_order_note(string $note): int
    {
        $this->notes[] = $note;
        return count($this->notes);
    }

    public function update_status(string $status, string $note = ''): bool
    {
        $this->status = $status;
        if ($note !== '') {
            $this->notes[] = $note;
        }
        return true;
    }

    public function payment_complete(string $transactionId = ''): bool
    {
        $this->paid = true;
        $this->status = 'processing';
        $this->transactionId = $transactionId;
        return true;
    }

    /** @param string|list<string> $status */
    public function has_status(string|array $status): bool
    {
        return in_array($this->status, (array) $status, true);
    }
}
