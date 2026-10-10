<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Canceled = 'canceled';

    /** Status que contam como venda (entram no faturamento). */
    public static function revenueStatuses(): array
    {
        return array_map(fn (self $s) => $s->value, [
            self::Pending, self::Preparing, self::OutForDelivery, self::Delivered,
        ]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Preparing => 'Em preparo',
            self::OutForDelivery => 'Saiu para entrega',
            self::Delivered => 'Entregue',
            self::Canceled => 'Cancelado',
        };
    }
}
