<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20261015000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit role delegation and protection from delegated user administration.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role ADD is_protected TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE users ADD is_protected TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE role_delegation (
                administrator_role_id INT NOT NULL,
                assignable_role_id INT NOT NULL,
                INDEX IDX_DELEGATION_ADMIN (administrator_role_id),
                INDEX IDX_DELEGATION_ASSIGNABLE (assignable_role_id),
                PRIMARY KEY(administrator_role_id, assignable_role_id),
                CONSTRAINT FK_DELEGATION_ADMIN FOREIGN KEY (administrator_role_id) REFERENCES role (id) ON DELETE CASCADE,
                CONSTRAINT FK_DELEGATION_ASSIGNABLE FOREIGN KEY (assignable_role_id) REFERENCES role (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE role_delegation');
        $this->addSql('ALTER TABLE role DROP is_protected');
        $this->addSql('ALTER TABLE users DROP is_protected');
    }
}
