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
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

        $this->validateArray(
            ['facNitSec' => $facNitSec, 'date' => $date, 'limit' => $limit],
            [
                'facNitSec' => 'required|integer|min_value:1',
                'date' => 'required|date',
                'limit' => 'nullable|integer|min_value:1|max_value:1000'
            ]
        );

        $invoices = $this->model->getInvoices($facNitSec, $date, $limit);
        Response::success($invoices);
    }

    public function search(): void
    {
        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'date' => 'required|date',
            'limit' => 'nullable|integer|min_value:1|max_value:1000'
        ]);

        $limit = isset($data['limit']) ? (int)$data['limit'] : 100;

        $invoices = $this->model->getInvoices((int)$data['facNitSec'], (string)$data['date'], $limit);
        Response::success($invoices);
    }
}
