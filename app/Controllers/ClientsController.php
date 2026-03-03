<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ClientsModel;
use Core\Response;

class ClientsController extends Controller
{
    public function __construct()
    {
        $this->model = new ClientsModel();
    }

    public function index(): void
    {
        $clients = $this->model->getAllClients();
        Response::success($clients);
    }

    public function show(string $clientId): void
    {
        $this->validateArray(['clientId' => $clientId], [
            'clientId' => 'required|integer|min_value:1'
        ]);

        $client = $this->model->getClientById((int)$clientId);
        if (!$client) {
            Response::error('Cliente no encontrado', 404);
        }

        Response::success($client);
    }

    public function lookup(): void
    {
        $data = $this->validate([
            'clientId' => 'required|integer|min_value:1'
        ]);

        $clientId = (int)$data['clientId'];
        $client = $this->model->getClientById($clientId);
        if (!$client) {
            Response::error('Cliente no encontrado', 404);
        }

        Response::success($client);
    }
}
