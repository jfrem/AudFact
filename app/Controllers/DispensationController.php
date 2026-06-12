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

    public function show(string $DisId, string $DisDetNro): void
    {
        $this->validateArray(['DisId' => $DisId, 'DisDetNro' => $DisDetNro], [
            'DisId' => 'required|string|max:255',
            'DisDetNro' => 'required|string|max:255'
        ]);

        $rows = $this->model->getDispensationData([
            'DisId' => trim($DisId),
            'Dispensa' => trim($DisDetNro)
        ]);
        Response::success(DispensationModel::formatDispensation($rows));
    }

    public function lookup(): void
    {
        $data = $this->validate([
            'DisId' => 'string|max:255',
            'DisDetNro' => 'required|string|max:255'
        ]);

        $disDetNro = trim((string) $data['DisDetNro']);
        try {
            $disId = !empty($data['DisId'])
                ? trim((string) $data['DisId'])
                : $this->model->resolveIdentityByDisDetNro($disDetNro);
        } catch (\RuntimeException $e) {
            Response::error(
                'No se encontró la dispensación con el número proporcionado',
                404
            );
        }

        $rows = $this->model->getDispensationData([
            'DisId' => $disId,
            'Dispensa' => $disDetNro
        ]);
        Response::success(DispensationModel::formatDispensation($rows));
    }
}
