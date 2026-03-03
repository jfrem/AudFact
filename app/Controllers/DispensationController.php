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

        $dispensation = $this->model->getDispensationData($DisDetNro);
        Response::success($dispensation);
    }

    public function lookup(): void
    {
        $data = $this->validate([
            'DisDetNro' => 'required|string|max:255'
        ]);

        $DisDetNro = trim((string)$data['DisDetNro']);
        $dispensation = $this->model->getDispensationData($DisDetNro);
        Response::success($dispensation);
    }
}
