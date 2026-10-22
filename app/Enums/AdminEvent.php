<?php

namespace App\Enums;

/**
 * Tipos de evento gravados em `audit_logs` (RF-19, alterações administrativas). Por enquanto os da
 * criação (ADM-02) e da edição de usuário (ADM-03), da criação (ADM-05) e da edição de departamento
 * (ADM-06), da criação (ADM-08) e da edição de categoria (ADM-09), da criação (PER-02) e da edição de
 * papel (PER-03), da matriz de permissões (PER-04) e da criação (SLA-02) e da edição de política de SLA (SLA-03); os demais entram com seus próprios fluxos. A
 * situação tem eventos próprios, separados da alteração de dados, por ser a mudança que concede ou
 * retira o acesso do usuário, ou o uso do departamento ou da categoria em novos vínculos (RF-02, RF-04).
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
    case RoleCreated = 'role_created';
    case RoleUpdated = 'role_updated';
    case RolePermissionsUpdated = 'role_permissions_updated';
    case SlaPolicyCreated = 'sla_policy_created';
    case SlaPolicyUpdated = 'sla_policy_updated';
    case SlaPolicyDeactivated = 'sla_policy_deactivated';
    case SlaPolicyActivated = 'sla_policy_activated';

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
            self::RoleCreated => 'Papel criado',
            self::RoleUpdated => 'Papel alterado',
            self::RolePermissionsUpdated => 'Permissões do papel alteradas',
            self::SlaPolicyCreated => 'Política de SLA criada',
            self::SlaPolicyUpdated => 'Metas da política de SLA alteradas',
            self::SlaPolicyDeactivated => 'Política de SLA inativada',
            self::SlaPolicyActivated => 'Política de SLA ativada',
        };
    }
}
