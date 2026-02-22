<?php
namespace App\Controllers;

use Core\Response;
use Core\Database;

class HealthController extends Controller
{
    public function status()
    {
        $status = [
            'status' => 'healthy',
            'timestamp' => time(),
            'services' => [
                'database' => $this->checkDatabase()
            ]
        ];
        
        Response::success($status);
    }
    
    private function checkDatabase(): string
    {
        try {
            Database::getConnection()->query('SELECT 1');
            return 'connected';
        } catch (\Exception $e) {
            return 'disconnected';
        }
    }
}
