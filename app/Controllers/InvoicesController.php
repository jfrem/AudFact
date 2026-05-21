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
        $dateFrom = $_GET['dateFrom'] ?? '';
        $dateTo = $_GET['dateTo'] ?? null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

        $this->validateArray(
            ['facNitSec' => $facNitSec, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'limit' => $limit],
            [
                'facNitSec' => 'required|integer|min_value:1',
                'dateFrom' => 'required|date',
                'dateTo' => 'optional|date',
                'limit' => 'nullable|integer|min_value:1|max_value:1000'
            ]
        );

        // Normalizar: si dateTo ausente, consultar un solo día
        $dateTo = ($dateTo !== null && $dateTo !== '') ? $dateTo : $dateFrom;

        $dtFrom = \DateTime::createFromFormat('Y-m-d', $dateFrom);
        $dtTo = \DateTime::createFromFormat('Y-m-d', $dateTo);
        if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
            Response::error('dateFrom no puede ser mayor que dateTo', 422);
        }

        $invoices = $this->model->getInvoices($facNitSec, $dateFrom, $dateTo, $limit);
        Response::success($invoices);
    }

    public function search(): void
    {
        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'dateFrom' => 'required|date',
            'dateTo' => 'optional|date',
            'limit' => 'nullable|integer|min_value:1|max_value:1000'
        ]);

        $limit = isset($data['limit']) ? (int)$data['limit'] : 100;
        // Normalizar: si dateTo ausente, consultar un solo día
        $dateTo = (isset($data['dateTo']) && $data['dateTo'] !== '') ? (string)$data['dateTo'] : (string)$data['dateFrom'];

        $dtFrom = \DateTime::createFromFormat('Y-m-d', (string)$data['dateFrom']);
        $dtTo = \DateTime::createFromFormat('Y-m-d', $dateTo);
        if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
            Response::error('dateFrom no puede ser mayor que dateTo', 422);
        }

        $invoices = $this->model->getInvoices((int)$data['facNitSec'], (string)$data['dateFrom'], $dateTo, $limit);
        Response::success($invoices);
    }
}
