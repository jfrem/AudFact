<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\InvoicesModel;
use Core\Response;

class InvoicesController extends Controller
{
    public function __construct()
    {
        $this->model = new InvoicesModel();
    }

    public function index(): void
    {
        $facNitSec = isset($_GET['facNitSec']) ? (int)$_GET['facNitSec'] : 0;
        $date = $_GET['date'] ?? '';
        $dateTo = $_GET['dateTo'] ?? null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

        $this->validateArray(
            ['facNitSec' => $facNitSec, 'date' => $date, 'dateTo' => $dateTo, 'limit' => $limit],
            [
                'facNitSec' => 'required|integer|min_value:1',
                'date' => 'required|date',
                'dateTo' => 'optional|date',
                'limit' => 'nullable|integer|min_value:1|max_value:1000'
            ]
        );

        if ($dateTo !== null && $dateTo !== '' && $date > $dateTo) {
            Response::error('date no puede ser mayor que dateTo', 422);
        }

        $invoices = $this->model->getInvoices($facNitSec, $date, $dateTo !== '' ? $dateTo : null, $limit);
        Response::success($invoices);
    }

    public function search(): void
    {
        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'date' => 'required|date',
            'dateTo' => 'optional|date',
            'limit' => 'nullable|integer|min_value:1|max_value:1000'
        ]);

        if (isset($data['dateTo']) && $data['dateTo'] !== '' && $data['date'] > $data['dateTo']) {
            Response::error('date no puede ser mayor que dateTo', 422);
        }

        $limit = isset($data['limit']) ? (int)$data['limit'] : 100;
        $dateTo = (isset($data['dateTo']) && $data['dateTo'] !== '') ? (string)$data['dateTo'] : null;

        $invoices = $this->model->getInvoices((int)$data['facNitSec'], (string)$data['date'], $dateTo, $limit);
        Response::success($invoices);
    }
}
