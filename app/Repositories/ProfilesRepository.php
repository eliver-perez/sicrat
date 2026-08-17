<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ProfilesRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getConnection() : PDO {
        return $this->db;
    }
    
    
}