<?php

function kiwiClassStatusOptions(): array
{
    return ['Pending', 'Ongoing', 'Active', 'Cancelled'];
}

function kiwiClassStatusCssClass(string $status): string
{
    $normalizedStatus = strtolower(trim($status));

    switch ($normalizedStatus) {
        case 'pending':
            return 'is-pending';
        case 'ongoing':
            return 'is-ongoing';
        case 'active':
            return 'is-active';
        case 'cancelled':
        case 'inactive':
            return 'is-cancelled';
        default:
            return 'is-pending';
    }
}

function kiwiClassStatusBootstrapClass(string $status): string
{
    $normalizedStatus = strtolower(trim($status));

    switch ($normalizedStatus) {
        case 'pending':
            return 'text-bg-warning';
        case 'ongoing':
            return 'text-bg-primary';
        case 'active':
            return 'text-bg-success';
        case 'cancelled':
        case 'inactive':
            return 'text-bg-danger';
        default:
            return 'text-bg-secondary';
    }
}
