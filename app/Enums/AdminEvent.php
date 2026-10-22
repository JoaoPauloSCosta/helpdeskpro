<?php

namespace App\Enums;

/**
 * Tipos de evento gravados em `audit_logs` (RF-19, alterações administrativas). Por enquanto os da
 * criação (ADM-02) e da edição de usuário (ADM-03), da criação (ADM-05) e da edição de departamento
 * (ADM-06) e da criação (ADM-08) e da edição de categoria (ADM-09); os demais entram com seus próprios
 * fluxos. A situação tem eventos próprios, separados da alteração de dados, por ser a mudança que
 * concede ou retira o acesso do usuário, ou o uso do departamento ou da categoria em novos vínculos
 * (RF-02, RF-04).
 */
enum AdminEvent: string
{
    case UserCreated = 'user_created';
    case UserUpdated = 'user_updated';
    case UserDeactivated = 'user_deactivated';
    case UserActivated = 'user_activated';
    case DepartmentCreated = 'department_created';
    case DepartmentUpdated = 'department_updated';
    case DepartmentDeactivated = 'department_deactivated';
    case DepartmentActivated = 'department_activated';
    case CategoryCreated = 'category_created';
    case CategoryUpdated = 'category_updated';
    case CategoryDeactivated = 'category_deactivated';
    case CategoryActivated = 'category_activated';

    public function label(): string
    {
        return match ($this) {
            self::UserCreated => 'Usuário criado',
            self::UserUpdated => 'Usuário alterado',
            self::UserDeactivated => 'Usuário inativado',
            self::UserActivated => 'Usuário reativado',
            self::DepartmentCreated => 'Departamento criado',
            self::DepartmentUpdated => 'Departamento alterado',
            self::DepartmentDeactivated => 'Departamento inativado',
            self::DepartmentActivated => 'Departamento reativado',
            self::CategoryCreated => 'Categoria criada',
            self::CategoryUpdated => 'Categoria alterada',
            self::CategoryDeactivated => 'Categoria inativada',
            self::CategoryActivated => 'Categoria reativada',
        };
    }
}
