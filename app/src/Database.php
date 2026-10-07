<?php

declare(strict_types=1);

namespace App;

use MongoDB\Client;
use MongoDB\Collection;

/**
 * Created once per worker process and reused across every request handled
 * by that worker (see public/worker.php) instead of reconnecting per request.
 */
final class Database
{
    private static ?Client $client = null;

    public static function client(): Client
    {
        return self::$client ??= new Client($_ENV['MONGODB_URI'] ?? 'mongodb://mongo:27017');
    }

    public static function orders(): Collection
    {
        $dbName = $_ENV['MONGODB_DB'] ?? 'shop';

        return self::client()->selectDatabase($dbName)->selectCollection('orders');
    }
}
