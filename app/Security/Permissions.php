<?php
declare(strict_types=1);

namespace Borealis\Security;

/** Catalog of permissions and the default roles created for every workspace. */
final class Permissions
{
    public const WORKSPACE = [
        'workspace.manage' => 'Configurar el espacio, prioridades, exportaciones y copias',
        'members.manage' => 'Invitar, crear y administrar usuarios',
        'roles.manage' => 'Administrar roles y permisos',
        'teams.create' => 'Crear equipos',
        'teams.manage' => 'Administrar cualquier equipo',
        'projects.create' => 'Crear proyectos',
        'projects.manage_all' => 'Ver y administrar todos los proyectos del espacio, aunque no participe en ellos',
        'audit.view' => 'Consultar el historial de acciones',
    ];

    public const PROJECT = [
        'project.view' => 'Ver el proyecto y sus tareas',
        'project.manage' => 'Editar proyecto, estados, etiquetas, módulos e integrantes',
        'project.archive' => 'Archivar y restaurar el proyecto',
        'task.create' => 'Crear tareas',
        'task.edit' => 'Editar y mover tareas',
        'task.delete' => 'Eliminar tareas',
        'comment.create' => 'Comentar',
        'file.upload' => 'Subir archivos',
        'file.delete' => 'Eliminar archivos de otras personas',
        'sprint.manage' => 'Planificar, iniciar y finalizar sprints',
        'doc.edit' => 'Editar documentación',
    ];

    /** key => [name, description, permissions, rank] */
    public static function defaultWorkspaceRoles(): array
    {
        $all = array_keys(self::WORKSPACE);
        return [
            'owner' => ['Propietario', 'Control total del espacio', $all, 0],
            'admin' => ['Administrador', 'Gestiona el espacio, usuarios y proyectos', $all, 1],
            'member' => ['Integrante', 'Crea equipos y proyectos y participa donde se le incluya', ['teams.create', 'projects.create'], 2],
            'guest' => ['Invitado', 'Solo accede a los proyectos donde se le agregue', [], 3],
        ];
    }

    public static function defaultProjectRoles(): array
    {
        return [
            'manager' => ['Responsable', 'Administra el proyecto', array_keys(self::PROJECT), 0],
            'contributor' => ['Colaborador', 'Crea y actualiza tareas', ['project.view', 'task.create', 'task.edit', 'comment.create', 'file.upload', 'doc.edit'], 1],
            'observer' => ['Observador', 'Solo consulta', ['project.view'], 2],
        ];
    }
}
