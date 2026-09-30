<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

use Maguari\Server\Kernel\Database\Database;

final class AdministratorRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function exists(): bool
    {
        return $this->database->pdo()->query('SELECT 1 FROM access_administrators LIMIT 1')->fetchColumn() !== false;
    }

    public function findById(int $id): ?Administrator
    {
        $statement = $this->database->pdo()->prepare('SELECT id, name, email FROM access_administrators WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : new Administrator((int) $row['id'], $row['name'], $row['email']);
    }

    /**
     * @return array{Administrator, string}|null the administrator and their password hash
     */
    public function findWithPasswordHash(string $email): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id, name, email, password_hash FROM access_administrators WHERE email = ?',
        );
        $statement->execute([$email]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [new Administrator((int) $row['id'], $row['name'], $row['email']), $row['password_hash']];
    }

    public function create(string $name, string $email, string $passwordHash, int $createdAt): Administrator
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO access_administrators (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$name, $email, $passwordHash, $createdAt]);

        return new Administrator((int) $pdo->lastInsertId(), $name, $email);
    }
}
