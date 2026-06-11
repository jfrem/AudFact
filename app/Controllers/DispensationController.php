<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\DispensationModel;
use Core\Response;

class DispensationController extends Controller
{
    public function __construct()
    {
        $this->model = new DispensationModel();
    }

    public function show(string $DisDetNro): void
    {
        $this->validateArray(['DisDetNro' => $DisDetNro], [
            'DisDetNro' => 'required|string|max:255'
        ]);
        $DisDetNro = trim($DisDetNro);

        $rows = $this->model->getDispensationData(['Dispensa' => $DisDetNro]);
        Response::success(DispensationModel::formatDispensation($rows));
    }

    public function lookup(): void
    {
        $data = $this->validate([
            'DisDetNro' => 'required|string|max:255'
        ]);

        $DisDetNro = trim((string) $data['DisDetNro']);
        $rows = $this->model->getDispensationData(['Dispensa' => $DisDetNro]);
        Response::success(DispensationModel::formatDispensation($rows));
    }
}
