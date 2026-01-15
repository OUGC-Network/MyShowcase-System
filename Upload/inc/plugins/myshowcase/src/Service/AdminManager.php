<?php

declare(strict_types=1);

namespace MyShowcase\Service;

use DB_MySQLi;
use DB_PgSQL;
use DB_SQLite;
use DefaultPage;
use Form;
use FormContainer;
use MyBB;
use MyLanguage;
use MyShowcase\Enum\CacheDataTypes;
use MyShowcase\Enum\FormTypes;
use MyShowcase\Enum\ModeratorPermissions;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Repository\ShowcasesRepository;
use MyShowcase\Security\PermissionsFactory;
use MyShowcase\Showcase;
use MyShowcase\Util\UrlUtil;
use MysqlPdoDbDriver;
use PopupMenu;
use PostgresPdoDbDriver;

use function MyShowcase\Admin\buildPermissionsRow;
use function MyShowcase\Admin\dbVerifyTables;
use function MyShowcase\Plugin\Functions\castTableFieldValue;
use function MyShowcase\Plugin\Functions\dataTableStructureGet;
use function MyShowcase\Plugin\Functions\fieldsetGet;
use function MyShowcase\Plugin\Functions\hooksRun;
use function MyShowcase\Plugin\Functions\loadLanguage;
use function MyShowcase\Plugin\Functions\showcaseDataTableDrop;
use function MyShowcase\Plugin\Functions\showcaseDataTableExists;
use function MyShowcase\Plugin\Functions\showcaseDelete;
use function MyShowcase\Plugin\Functions\showcaseGet;

use const MyShowcase\Plugin\Core\MODERATOR_TYPE_GROUP;
use const MyShowcase\Plugin\Core\MODERATOR_TYPE_USER;
use const MyShowcase\Plugin\Core\TABLES_DATA;

class AdminManager
{
    private CacheManager $cache_manager;

    private UrlUtil $url;

    private array $tabs;

    private int $showcase_id;

    private int $group_id;

    private int $permission_id;

    private array $showcase_input;

    private array $showcases_cache;

    private array $permissions_cache;

    private array $groups_cache;

    public function __construct(
        private readonly DB_SQLite|DB_PgSQL|PostgresPdoDbDriver|MysqlPdoDbDriver|DB_MySQLi $database,
        private readonly MyBB $mybb,
        private readonly MyLanguage $language,
        private readonly DefaultPage $page,
    ) {
        $this->url = new UrlUtil('index.php?module=myshowcase-summary');

        $this->tabs = [
            'myShowcaseAdminSummary' => [
                'title' => $this->language->myShowcaseAdminSummary,
                'link' => $this->url->build(),
                'description' => $this->language->myShowcaseAdminSummaryDescription
            ],
            'myShowcaseAdminSummaryNew' => [
                'title' => $this->language->myShowcaseAdminSummaryNew,
                'link' => $this->url->build(['action' => 'create']),
                'description' => $this->language->myShowcaseAdminSummaryNewDescription
            ],
        ];

        $this->showcase_id = $this->mybb->get_input('showcase_id', MyBB::INPUT_INT);

        $this->group_id = $this->mybb->get_input('group_id', MyBB::INPUT_INT);

        $this->permission_id = $this->mybb->get_input('permission_id', MyBB::INPUT_INT);

        $this->showcase_input = $this->mybb->get_input('showcase', MyBB::INPUT_ARRAY);

        $this->cache_manager = new CacheManager($this->database, $this->mybb->cache,);

        $this->showcases_cache = $this->cache_manager->get(CacheDataTypes::Config);

        $this->permissions_cache = $this->cache_manager->get(CacheDataTypes::Permissions);

        $this->groups_cache = $this->mybb->cache->read('usergroups') ?? [];

        loadLanguage();

        $this->page->add_breadcrumb_item($this->language->myShowcaseSystem, $this->url->build());

        $this->page->add_breadcrumb_item($this->language->myShowcaseAdminSummary, $this->url->build());

        if ($this->mybb->get_input('action') === 'update') {
            $this->tabs['myShowcaseAdminSummaryEdit'] = [
                'title' => $this->language->myShowcaseAdminSummaryEdit,
                'link' => $this->url->build(['action' => 'update', 'showcase_id' => $this->showcase_id]),
                'description' => $this->language->myShowcaseAdminSummaryEditDescription
            ];
        }

        $fieldsetObjects = (function (): array {
            return array_map(function ($fieldsetData) {
                return $fieldsetData['set_name'];
            }, $this->cache_manager->get(CacheDataTypes::Fieldsets));
        })();

        $this->permissions_cache = $this->cache_manager->get(CacheDataTypes::Permissions);

        hooksRun('admin_summary_start');

        $this->page->extra_header .= <<<EOL
<style type="text/css">
    .user_settings_bit label {
        font-weight: normal;
    }
</style>
EOL;

        if ($this->mybb->get_input('action') === 'clear_permission') {
            $this->showcaseClearPermissions();
        } elseif ($this->mybb->get_input('action') === 'permissions') {
            $this->showcasePermissions();
        } elseif ($this->mybb->get_input('action') === 'create' || $this->mybb->get_input('action') === 'update') {
            $this->showcaseCreateUpdate();
        } elseif (in_array($this->mybb->get_input('action'), ['tableCreate', 'tableRebuild'])) {
            $this->showcaseTableCreateRebuild();
        } elseif ($this->mybb->get_input('action') === 'tableDrop') {
            $this->showcaseTableDrop();
        } elseif ($this->mybb->get_input('action') === 'delete') {
            $this->showcaseDelete();
        } else {
            $this->showcaseMain();
        }

        exit;
    }

    public function showcaseClearPermissions(): void
    {
        $showcase = new Showcase($this->database, $this->mybb, $this->language, showcase_id: $this->showcase_id);

        if (empty($showcase->config)) {
            flash_message($this->language->myShowcaseAdminErrorInvalidShowcase, 'error');

            admin_redirect($this->tabs['myShowcaseAdminSummary']['link']);
        }

        $permission_data = $showcase->permissions_repository->queryPermission(["group_id='{$this->group_id}'"],
            'permission_id');

        if (isset($this->mybb->input['no']) || !$permission_data) {
            admin_redirect(
                $this->url->build(
                    ['action' => 'update', 'type' => 'permissions', 'showcase_id' => $this->showcase_id]
                ) . '#tab_permissions'
            );
        }

        hooksRun('admin_forum_management_clear_permission', $this);

        if ($this->mybb->request_method === 'post') {
            $showcase->permissions_repository->deletePermission($permission_data['permission_id']);

            hooksRun('admin_summary_clear_permissions_post');

            $showcase->cache_manager->update(CacheDataTypes::Permissions);

            flash_message($this->language->myShowcaseAdminSummaryPermissionsClearSuccess, 'success');

            admin_redirect(
                $this->url->build(
                    ['action' => 'update', 'type' => 'permissions', 'showcase_id' => $this->showcase_id]
                ) . '#tab_permissions'
            );
        } else {
            $this->page->output_confirm_action(
                $this->url->build(
                    [
                        'action' => 'clear_permission',
                        'showcase_id' => $this->showcase_id,
                        'permission_id' => $this->permission_id,
                        'my_post_key' => $this->mybb->post_code
                    ]
                ),
                $this->language->myShowcaseAdminSummaryPermissionsClearConfirm
            );
        }
    }

    public function showcasePermissions(): void
    {
        $showcase = new Showcase($this->database, $this->mybb, $this->language, showcase_id: $this->showcase_id);

        if (empty($showcase->config)) {
            flash_message($this->language->myShowcaseAdminErrorInvalidShowcase, 'error');

            admin_redirect($this->tabs['myShowcaseAdminSummary']['link']);
        }

        hooksRun('admin_forum_management_permissions', $this);

        $group_permission_data = $showcase->permissions_repository->queryPermission(
            ["group_id='{$this->group_id}'"],
            array_keys(TABLES_DATA['myshowcase_permissions']),
        );

        $has_custom_permissions = true;

        if (empty($group_permission_data)) {
            $has_custom_permissions = false;

            $permissions_repository = new PermissionsRepository($this->database);

            $group_permission_data = $permissions_repository->queryPermission(
                ["group_id='{$this->group_id}'"],
                array_keys(TABLES_DATA['myshowcase_permissions']),
            );

            if ($group_permission_data) {
                $this->group_id = $group_permission_data['group_id'];
            }
        }

        if ($this->mybb->request_method === 'post') {
            $insert_data = [
                'group_id' => $this->group_id,
            ];

            $field_list = [];

            foreach (PermissionsFactory::$permission_options_map as $permission_name => $permission_definition) {
                if (empty($permission_definition['is_group_permission'])) {
                    continue;
                }

                if (isset($this->showcase_input[$permission_name])) {
                    $insert_data[$permission_name] = $this->showcase_input[$permission_name];
                }
            }

            hooksRun('admin_forum_management_permissions_commit', $this);

            if ($has_custom_permissions) {
                $showcase->permissions_repository->updatePermission(
                    $insert_data,
                    $group_permission_data['permission_id']
                );
            } else {
                $showcase->permissions_repository->insertPermission($insert_data);
            }

            $this->cache_manager->update(CacheDataTypes::Permissions);

            log_admin_action($this->showcase_id, $showcase->config['name']);

            flash_message($this->language->myShowcaseAdminSummaryPermissionsFormCustomPermissionsSuccess, 'success');

            admin_redirect(
                $this->url->build(
                    ['action' => 'update', 'showcase_id' => $this->showcase_id]
                ) . '#tab_permissions'
            );
        }

        $sub_tabs = [];

        $this->page->add_breadcrumb_item($this->language->myShowcaseAdminSummaryPermissionsFormCustomPermissions);

        $permissions_url = $this->url->build(
            [
                'action' => 'permissions',
                'showcase_id' => $this->showcase_id,
                'group_id' => $this->group_id
            ]
        );

        $sub_tabs['edit_permissions'] = [
            'title' => $this->language->myShowcaseAdminSummaryPermissionsFormCustomPermissions,
            'link' => $permissions_url,
            'description' => $this->language->myShowcaseAdminSummaryPermissionsFormCustomPermissionsDescription
        ];

        $this->page->output_header($this->language->myShowcaseAdminSummaryPermissionsFormCustomPermissions);

        $this->page->output_nav_tabs($sub_tabs, 'edit_permissions');

        $permissions_url = $this->url->build(
            [
                'action' => 'permissions',
                'showcase_id' => $this->showcase_id,
                'group_id' => $this->group_id
            ]
        );

        $form = new Form($permissions_url, 'post');

        $field_list = [];

        foreach (PermissionsFactory::$permission_options_map as $permission_name => $permission_definition) {
            if (empty($permission_definition['is_group_permission'])) {
                continue;
            }

            $field_list[$permission_definition['category']][$permission_name] = $this->language->{'myshowcase_admin_permissions_' . $permission_name} ?? 'myshowcase_admin_permissions_' . $permission_name;
        }

        $tabs = [];

        foreach (array_keys($field_list) as $category) {
            $tabs[$category] = $this->language->{'myshowcase_admin_permissions_category_' . $category} ?? 'myshowcase_admin_permissions_category_' . $category;
        }

        $this->page->output_tab_control($tabs);

        foreach (array_keys($field_list) as $category) {
            echo "<div id=\"tab_" . $category . "\">\n";

            $form_container = new FormContainer(
                "\"" . htmlspecialchars_uni(
                    $this->groups_cache[$this->group_id]['title']
                ) . "\" " . $this->language->myShowcaseAdminSummaryPermissionsFormCustomPermissions
            );

            $permissions_list = [];

            foreach ($field_list[$category] as $permission_name => $permission_title) {
                $permission_definition = PermissionsFactory::$permission_options_map[$permission_name];

                $permissions_list[] = buildPermissionsRow(
                    $this->mybb,
                    $form,
                    $this->language,
                    $permission_name,
                    $permission_definition,
                    '',
                    'myshowcase_admin_permissions',
                    true,
                    array_merge(
                        $group_permission_data,
                        $this->showcase_input
                    )
                );
            }

            $form_container->output_row(
                '',
                '',
                "<div class=\"forum_settings_bit\">" . implode(
                    "</div><div class=\"forum_settings_bit\">",
                    $permissions_list
                ) . '</div>'
            );

            $form_container->end();

            echo '</div>';
        }

        $form->output_submit_wrapper(
            [$form->generate_submit_button($this->language->myShowcaseAdminSummaryPermissionsFormSave)]
        );

        $form->end();
    }

    private function showcaseCreateUpdate(): void
    {
        $is_create_page = $this->mybb->get_input('action') === 'create';

        if (!$is_create_page) {
            $showcase = new Showcase($this->database, $this->mybb, $this->language, showcase_id: $this->showcase_id);

            if (empty($showcase->config)) {
                flash_message($this->language->myShowcaseAdminErrorInvalidShowcase, 'error');

                admin_redirect($this->tabs['myShowcaseAdminSummary']['link']);
            }
        }

        $error_messages = [];

        if ($this->mybb->request_method === 'post') {
            switch ($this->mybb->get_input('type')) {
                case 'general':
                case 'main':
                case 'parser':
                case 'entries':
                case 'comments':
                case 'attachments':
                    $showcase_data = [];

                    foreach (TABLES_DATA['myshowcase_config'] as $field_name => $field_definition) {
                        if (empty($field_definition['category']) ||
                            $field_definition['category'] !== $this->mybb->get_input('type')) {
                            continue;
                        }

                        if (isset($this->showcase_input[$field_name])) {
                            $showcase_data[$field_name] = $this->showcase_input[$field_name];
                        }
                    }

                    if (isset($showcase_data['field_set_id']) && showcaseDataTableExists($this->showcase_id)) {
                        unset($showcase_data['field_set_id']);
                    }

                    if (isset($showcase_data['name']) && (
                            !$showcase_data['name'] ||
                            in_array($showcase_data['name'], array_column($this->showcases_cache, 'name')) &&
                            (function (string $showcaseName): bool {
                                $duplicatedName = false;

                                foreach ($this->showcases_cache as $showcaseData) {
                                    if ($showcaseData['name'] === $showcaseName && $showcaseData['showcase_id'] !== $this->showcase_id) {
                                        $duplicatedName = true;
                                    }
                                }

                                return $duplicatedName;
                            })(
                                $showcase_data['name']
                            )
                        )) {
                        $error_messages[] = $this->language->myShowcaseAdminErrorDuplicatedName;
                    }

                    if (!empty($showcase_data['route_identifier']) && (
                            in_array(
                                $showcase_data['route_identifier'],
                                array_column($this->showcases_cache, 'route_identifier')
                            ) &&
                            (function (string $route_identifier): bool {
                                $duplicateScript = false;

                                foreach ($this->showcases_cache as $showcaseData) {
                                    if ($showcaseData['route_identifier'] === $route_identifier && $showcaseData['showcase_id'] !== $this->showcase_id) {
                                        $duplicateScript = true;
                                    }
                                }

                                return $duplicateScript;
                            })(
                                $showcase_data['route_identifier']
                            )
                        )) {
                        $error_messages[] = $this->language->myShowcaseAdminErrorDuplicatedScriptFile;
                    }

                    if (isset($showcase_data['custom_template_prefix'])) {
                        $showcase_data['custom_template_prefix'] = preg_replace(
                            '/[^\da-z]/i',
                            '',
                            my_strtolower($showcase_data['custom_template_prefix'])
                        );
                    }

                    if (!empty($showcase_data['custom_template_prefix']) && (
                            in_array(
                                $showcase_data['custom_template_prefix'],
                                array_column($this->showcases_cache, 'custom_template_prefix')
                            ) &&
                            (function (string $templatePrefix): bool {
                                $duplicatedTemplatePrefix = false;

                                foreach ($this->showcases_cache as $showcaseData) {
                                    if ($showcaseData['custom_template_prefix'] === $templatePrefix && $showcaseData['showcase_id'] !== $this->showcase_id) {
                                        $duplicatedTemplatePrefix = true;
                                    }
                                }

                                return $duplicatedTemplatePrefix;
                            })(
                                $showcase_data['custom_template_prefix']
                            ))) {
                        $error_messages[] = $this->language->myShowcaseAdminErrorDuplicatedTemplatePrefix;
                    }

                    hooksRun('admin_summary_add_edit_post_main_other');

                    if (!$error_messages) {
                        if ($is_create_page) {
                            $showcase_repository = new ShowcasesRepository($this->database,);

                            $this->showcase_id = $showcase_repository->insertShowcase($showcase_data);
                        } else {
                            $showcase->showcase_repository->updateShowcase($showcase_data);
                        }

                        $this->cache_manager->update(CacheDataTypes::Config);

                        if ($is_create_page) {
                            log_admin_action(['showcase_id' => $this->showcase_id]);

                            flash_message($this->language->myShowcaseAdminSuccessNewShowcase, 'success');
                        } else {
                            log_admin_action(
                                ['showcase_id' => $this->showcase_id, 'type' => $this->mybb->get_input('type')]
                            );

                            flash_message($this->language->myShowcaseAdminSuccessShowcaseUpdated, 'success');
                        }

                        admin_redirect(
                            $this->url->build(
                                [
                                    'action' => 'update',
                                    'type' => $this->mybb->get_input('type'),
                                    'showcase_id' => $this->showcase_id
                                ]
                            ) . '#tab_' . $this->mybb->get_input('type')
                        );
                    }
                    break;
                case 'moderators':
                    if ($this->mybb->get_input('update') === 'permissions') {
                        foreach ($this->showcase_input as $moderatorID => $permissions) {
                            $permissions = array_map('intval', $permissions);

                            $moderator_data = [
                                ModeratorPermissions::EntriesCanView => $permissions[ModeratorPermissions::EntriesCanView] ?? 0,
                                ModeratorPermissions::EntriesCanView => $permissions[ModeratorPermissions::EntriesCanView] ?? 0,
                                ModeratorPermissions::EntriesCanView => $permissions[ModeratorPermissions::EntriesCanView] ?? 0,
                                ModeratorPermissions::CommentsCanView => $permissions[ModeratorPermissions::CommentsCanView] ?? 0,
                            ];

                            $showcase->moderators_repository->updateModerator($moderator_data, $moderatorID);
                        }
                    } elseif ($this->mybb->get_input('create') === 'group') {
                        $this->group_id = $this->mybb->get_input('usergroup', MyBB::INPUT_INT);

                        $query = $this->database->simple_select('usergroups', 'gid', "gid='{$this->group_id}'");

                        if (!$this->database->num_rows($query)) {
                            flash_message($this->language->myShowcaseAdminErrorInvalidGroup, 'error');

                            admin_redirect(
                                $this->url->build(
                                    ['action' => 'update', 'type' => 'moderators', 'showcase_id' => $this->showcase_id]
                                ) . '#tab_moderators'
                            );
                        }

                        $moderator_data = [
                            'showcase_id' => $this->showcase_id,
                            'user_id' => $this->group_id,
                            'is_group' => MODERATOR_TYPE_GROUP,
                            ModeratorPermissions::EntriesCanView => $this->mybb->get_input(
                                ModeratorPermissions::EntriesCanView,
                                MyBB::INPUT_INT
                            ),
                            ModeratorPermissions::CommentsCanView => $this->mybb->get_input(
                                ModeratorPermissions::CommentsCanView,
                                MyBB::INPUT_INT
                            ),
                            ModeratorPermissions::CanManageAttachments => $this->mybb->get_input(
                                ModeratorPermissions::CanManageAttachments,
                                MyBB::INPUT_INT
                            ),
                            ModeratorPermissions::CanManageReports => $this->mybb->get_input(
                                ModeratorPermissions::CanManageReports,
                                MyBB::INPUT_INT
                            ),
                        ];

                        $showcase->moderators_repository->insertModerator($moderator_data);
                    } elseif ($this->mybb->get_input('create') === 'user') {
                        $userData = get_user_by_username($this->mybb->get_input('username'));

                        if (empty($userData['uid'])) {
                            flash_message($this->language->myShowcaseAdminErrorInvalidGroup, 'error');

                            admin_redirect(
                                $this->url->build(
                                    ['action' => 'update', 'type' => 'moderators', 'showcase_id' => $this->showcase_id]
                                ) . '#tab_moderators'
                            );
                        }

                        $moderator_data = [
                            'showcase_id' => $this->showcase_id,
                            'user_id' => (int)$userData['uid'],
                            'is_group' => MODERATOR_TYPE_USER,
                            ModeratorPermissions::EntriesCanView => $this->mybb->get_input(
                                ModeratorPermissions::EntriesCanView,
                                MyBB::INPUT_INT
                            ),
                            ModeratorPermissions::CommentsCanView => $this->mybb->get_input(
                                ModeratorPermissions::CommentsCanView,
                                MyBB::INPUT_INT
                            ),
                            ModeratorPermissions::CanManageAttachments => $this->mybb->get_input(
                                ModeratorPermissions::CanManageAttachments,
                                MyBB::INPUT_INT
                            ),
                            ModeratorPermissions::CanManageReports => $this->mybb->get_input(
                                ModeratorPermissions::CanManageReports,
                                MyBB::INPUT_INT
                            ),
                        ];

                        $showcase->moderators_repository->insertModerator($moderator_data);
                    }

                    $this->cache_manager->update(CacheDataTypes::Moderators);

                    log_admin_action(['showcase_id' => $this->showcase_id, 'type' => $this->mybb->get_input('type')]);

                    flash_message($this->language->myShowcaseAdminSuccessShowcaseEditModerators, 'success');

                    admin_redirect(
                        $this->url->build(
                            ['action' => 'update', 'type' => 'moderators', 'showcase_id' => $this->showcase_id]
                        ) . '#tab_moderators'
                    );

                    break;
                case 'delete':
                    $showcase->moderators_repository->deleteModerator(
                        $this->mybb->get_input('moderator_id', MyBB::INPUT_INT)
                    );

                    $this->cache_manager->update(CacheDataTypes::Moderators);

                    log_admin_action(['showcase_id' => $this->showcase_id, 'type' => $this->mybb->get_input('type')]);

                    flash_message($this->language->myShowcaseAdminSuccessShowcaseEditModeratorDelete, 'success');

                    admin_redirect(
                        $this->url->build(
                            ['action' => 'update', 'type' => 'moderators', 'showcase_id' => $this->showcase_id]
                        ) . '#tab_moderators'
                    );
                    break;
            }
        }

        $this->page->add_breadcrumb_item(
            $this->language->myShowcaseAdminSummaryEdit,
            $this->url->build(['action' => $is_create_page ? 'create' : 'update', 'showcase_id' => $this->showcase_id])
        );

        $this->page->output_header($this->language->myShowcaseAdminSummaryEdit);

        if ($error_messages) {
            $this->page->output_inline_error($error_messages);
        }

        $categories = [];

        foreach (TABLES_DATA['myshowcase_config'] as $field_name => $field_definition) {
            if (!isset($field_definition['category'])) {
                continue;
            }

            $categories[$field_definition['category']] = $this->language->{'myshowcase_admin_summary_create_update_category_' . $field_definition['category']};
        }

        if (!$is_create_page) {
            $categories['permissions'] = $this->language->myshowcase_admin_summary_create_update_category_permissions;

            $categories['moderators'] = $this->language->myshowcase_admin_summary_create_update_category_moderators;
        }

        hooksRun('admin_summary_add_edit_start');

        if ($is_create_page) {
            $this->page->output_nav_tabs($this->tabs, 'myShowcaseAdminSummaryNew');
        } else {
            $this->page->output_nav_tabs($this->tabs, 'myShowcaseAdminSummaryEdit');
        }

        $this->page->output_tab_control($categories);

        $this->mybb->input = array_merge($showcase->config ?? [], $this->mybb->input);

        $categories_objects = [];

        foreach (TABLES_DATA['myshowcase_config'] as $field_name => $field_definition) {
            if (!isset($field_definition['category'])) {
                continue;
            }

            if (isset($field_definition['section'])) {
                $categories_objects[$field_definition['category']]['grouped'][$field_definition['section']][$field_name] = $field_definition;
            } else {
                $categories_objects[$field_definition['category']]['single'][$field_name] = $field_definition;
            }
        }

        foreach ($categories as $category_key => $category_title) {
            if (in_array($category_key, ['permissions', 'moderators'])) {
                continue;
            }

            echo "<div id=\"tab_{$category_key}\">\n";

            $form = new Form(
                $this->url->build(
                    [
                        'action' => $is_create_page ? 'create' : 'update',
                        'type' => $category_key,
                        'showcase_id' => $this->showcase_id
                    ]
                ) . '#tab_{$category_key}',
                'post',
                $is_create_page ? 'create' : 'update'
            );

            $form_container = new FormContainer(
                $this->language->{'myshowcase_admin_summary_create_update_category_' . $category_key . '_description'}
            );

            foreach ($categories_objects[$category_key]['single'] as $field_name => $field_definition) {
                $form_container->output_row(
                    $this->language->{'myshowcase_admin_summary_create_update_' . $field_name} ?? 'myshowcase_admin_summary_create_update_' . $field_name,
                    $this->language->{'myshowcase_admin_summary_create_update_' . $field_name . '_description'} ?? 'myshowcase_admin_summary_create_update_' . $field_name . '_description',
                    buildPermissionsRow(
                        $this->mybb,
                        $form,
                        $this->language,
                        $field_name,
                        $field_definition,
                        $category_key,
                        'myshowcase_admin_summary_create_update',
                        showcase_config: $showcase->config ?? ['showcase_id' => 0]
                    )
                );
            }

            foreach ($categories_objects[$category_key]['grouped'] ?? [] as $section => $section_settings) {
                $settingCode = '';

                foreach ($section_settings as $field_name => $field_definition) {
                    $settingCode .= buildPermissionsRow(
                        $this->mybb,
                        $form,
                        $this->language,
                        $field_name,
                        $field_definition,
                        $category_key,
                        'myshowcase_admin_summary_create_update',
                        true,
                    );
                }

                $form_container->output_row(
                    $this->language->{'myshowcase_admin_summary_create_update_' . $section},
                    '',
                    $settingCode
                );
            }

            $form_container->end();

            $form->output_submit_wrapper([
                $form->generate_submit_button($this->language->myShowcaseAdminButtonSubmit),
                $form->generate_reset_button($this->language->myShowcaseAdminButtonReset)
            ]);

            $form->end();

            echo "</div>\n";
        }

        if ($is_create_page) {
            hooksRun('admin_summary_add_edit_end');

            $this->page->output_footer();
        }

        //permissions tab
        echo "<div id=\"tab_permissions\">\n";

        $form = new Form(
            $this->url->build(
                ['action' => 'update', 'type' => 'permissions', 'showcase_id' => $this->showcase_id]
            ) . '#tab_permissions',
            'post',
            'update'
        );

        $permissions_repository = new PermissionsRepository($this->database, ['showcase_id' => $this->showcase_id]);

        foreach (
            $permissions_repository->queryPermissions(
                query_fields: array_keys(TABLES_DATA['myshowcase_permissions'])
            ) as $existing
        ) {
            $existing_permissions[$existing['group_id']] = $existing;
        }

        $this->permissions_cache = $this->cache_manager->get(CacheDataTypes::Permissions);

        $field_list = [];

        foreach (PermissionsFactory::$permission_options_map as $permission_name => $permission_definition) {
            if (empty($permission_definition['is_group_permission']) || empty($permission_definition['dragging'])) {
                continue;
            }

            $field_list[$permission_name] = $this->language->{'myshowcase_admin_permissions_' . $permission_name . '_dragging'} ?? 'myshowcase_admin_permissions_' . $permission_name . '_dragging';
        }

        $form_container = new FormContainer(
            $this->language->myShowcaseAdminSummaryPermissionsFormPermissions
        );

        $form_container->output_row_header(
            $this->language->myShowcaseAdminSummaryPermissionsFormGroup,
            ['class' => 'align_center', 'style' => 'width: 20%']
        );

        $form_container->output_row_header(
            $this->language->controls,
            ['class' => 'align_center', 'style' => 'width: 15%', 'colspan' => 2]
        );

        foreach ($this->groups_cache as $group_data) {
            $this->group_id = (int)$group_data['gid'];

            $has_custom_permissions = !empty($this->permissions_cache[$this->showcase_id][$this->group_id]);

            $group_title = format_name(htmlspecialchars_uni($group_data['title']), $group_data['gid']);

            if ($has_custom_permissions) {
                $inherited_text = $this->language->myShowcaseAdminSummaryPermissionsFormCustom;
            } else {
                $inherited_text = $this->language->myShowcaseAdminSummaryPermissionsFormInherited;
            }

            $form_container->output_cell(
                "<strong>{$group_title}</strong> <small style=\"vertical-align: middle;\">({$inherited_text})</small>"
            );

            $permissions_url = $this->url->build(
                [
                    'action' => 'permissions',
                    'showcase_id' => $this->showcase_id,
                    'group_id' => $this->group_id
                ]
            );

            if ($has_custom_permissions) {
                $form_container->output_cell(
                    "<a href=\"{$permissions_url}\">{$this->language->myShowcaseAdminSummaryPermissionsFormEdit}</a>",
                    ['class' => 'align_center']
                );

                $clear_permissions_url = $this->url->build(
                    [
                        'action' => 'clear_permission',
                        'showcase_id' => $this->showcase_id,
                        'group_id' => $this->group_id,
                        'my_post_key' => $this->mybb->post_code
                    ]
                );

                $form_container->output_cell(
                    "<a href=\"{$clear_permissions_url}\" onclick=\"return AdminCP.deleteConfirmation(this, '{$this->language->myShowcaseAdminSummaryPermissionsFormConfirmClear}')\">{$this->language->myShowcaseAdminSummaryPermissionsFormClear}</a>",
                    ['class' => 'align_center']
                );
            } else {
                $form_container->output_cell(
                    "<a href=\"{$permissions_url}\">{$this->language->myShowcaseAdminSummaryPermissionsFormSet}</a>",
                    ['class' => 'align_center', 'colspan' => 2]
                );
            }

            $form_container->construct_row(['id' => 'row_' . $this->group_id]);
        }

        $form_container->end();

        $form->output_submit_wrapper(
            [$form->generate_submit_button($this->language->myShowcaseAdminSummaryPermissionsFormButtonSubmit)]
        );

        $form->end();

        echo "</div>\n";

        echo "<div id=\"tab_moderators\">\n";

        $form = new Form(
            $this->url->build(['action' => 'update', 'type' => 'moderators', 'showcase_id' => $this->showcase_id]
            ) . '#tab_moderators',
            'post',
            'management'
        );

        $form_container = new FormContainer(
            $this->language->sprintf(
                $this->language->myShowcaseAdminSummaryEditModeratorPermissionsAssigned,
                $showcase->config['name']
            )
        );

        $form_container->output_row_header($this->language->myShowcaseAdminSummaryEditModeratorPermissionsName);

        $form_container->output_row_header(
            $this->language->myShowcaseAdminSummaryEditModeratorPermissionsControls,
            ['width' => '30%', 'class' => 'align_center', 'colspan' => 2]
        );

        foreach (
            $showcase->moderators_repository->queryModerators(
                query_fields: [
                    'moderator_id',
                    'user_id',
                    'group_id',
                    'is_group',
                    'showcase_id',
                ],
                query_options: ['order_by' => 'is_group']
            ) as $moderator_data
        ) {
            if (!empty($moderator_data['is_group'])) {
                $moderator_data['img'] = "<img src=\"styles/{$this->page->style}/images/icons/group.png\" alt=\"{$this->language->myshowcase_moderators_group}\" title=\"{$this->language->myshowcase_moderators_group}\" />";

                foreach ($this->groups_cache as $group_data) {
                    if ($group_data['gid'] === $moderator_data['user_id']) {
                        $moderator_data['title'] = $group_data['title'];
                    }
                }

                $form_container->output_cell(
                    "{$moderator_data['img']} <a href=\"index.php?module=user-groups&amp;action=update&amp;group_id={$moderator_data['group_id']}\">" . htmlspecialchars_uni(
                        $moderator_data['title']
                    ) . '</a>'
                );
            } else {
                $moderator_data['img'] = "<img src=\"styles/{$this->page->style}/images/icons/user.png\" alt=\"{$this->language->myshowcase_moderators_user}\" title=\"{$this->language->myshowcase_moderators_user}\" />";

                $userData = get_user($moderator_data['user_id']);

                $form_container->output_cell(
                    "{$moderator_data['img']} <a href=\"index.php?module=user-users&amp;action=update&amp;user_id={$moderator_data['showcase_id']}\">" . htmlspecialchars_uni(
                        $userData['username']
                    ) . '</a>'
                );
            }

            $edit_moderator_url = $this->url->build([
                'action' => 'update',
                'type' => 'moderator',
                'showcase_id' => $this->showcase_id,
                'moderator_id' => $moderator_data['moderator_id'],
                'my_post_key' => $this->mybb->post_code
            ]);

            $form_container->output_cell(
                "<a href=\"{$edit_moderator_url}\">{$this->language->myShowcaseAdminButtonEdit}</a>",
                ['class' => 'align_center']
            );

            $delete_moderator_url = $this->url->build([
                    'action' => 'update',
                    'type' => 'delete',
                    'showcase_id' => $this->showcase_id,
                    'moderator_id' => $moderator_data['moderator_id'],
                    'my_post_key' => $this->mybb->post_code
                ]) . '#tab_moderators';

            $form_container->output_cell(
                "<a href=\"{$delete_moderator_url}\" onclick=\"return AdminCP.deleteConfirmation(this, '{$this->language->myShowcaseAdminConfirmModeratorDelete}')\">{$this->language->myShowcaseAdminButtonDelete}</a>",
                ['class' => 'align_center']
            );

            $form_container->construct_row();
        }

        if (!$form_container->num_rows()) {
            $form_container->output_cell(
                $this->language->myShowcaseAdminSummaryEditModeratorPermissionsEmpty,
                ['colspan' => 6, 'class' => 'align_center']
            );

            $form_container->construct_row();
        }

        $form_container->end();

        $form->output_submit_wrapper(
            [$form->generate_submit_button($this->language->myShowcaseAdminButtonSubmitSaveModeratorPermissions)]
        );

        $form->end();

        echo '<br />';

        // Add Usergropups
        $form = new Form(
            $this->url->build(['action' => 'update', 'type' => 'moderators', 'showcase_id' => $this->showcase_id]
            ) . '#tab_moderators',
            'post',
            'management'
        );

        echo $form->generate_hidden_field('create', 'group');

        $form_container = new FormContainer($this->language->myShowcaseAdminSummaryEditModeratorPermissionsAddGroup);

        $form_container->output_row(
            $this->language->myShowcaseAdminSummaryEditModeratorPermissionsAddGroupGroup . ' <em>*</em>',
            $this->language->myShowcaseAdminSummaryEditModeratorPermissionsAddGroupGroupDescription,
            $form->generate_select_box(
                'usergroup',
                (function (): array {
                    $groupObjects = [];

                    foreach ($this->groups_cache as $groupData) {
                        $this->group_id = $groupData['gid'];

                        $group_title = htmlspecialchars_uni($groupData['title']);

                        $groupObjects[$this->group_id] = $this->language->myShowcaseAdminUserGroup . ' ' . $this->group_id . ': ' . $group_title;
                    }

                    return $groupObjects;
                })(),
                $this->mybb->get_input('usergroup'),
                ['id' => 'usergroup']
            ),
            'usergroup'
        );

        $form_container->end();

        $form->output_submit_wrapper(
            [$form->generate_submit_button($this->language->myShowcaseAdminButtonSubmitAddModeratorGroup)]
        );

        $form->end();

        echo '<br />';

        //create Users
        $form = new Form(
            $this->url->build(['action' => 'update', 'type' => 'moderators', 'showcase_id' => $this->showcase_id]
            ) . '#tab_moderators',
            'post',
            'management'
        );

        echo $form->generate_hidden_field('create', 'user');

        $form_container = new FormContainer($this->language->myShowcaseAdminSummaryEditModeratorPermissionsAddUser);

        $form_container->output_row(
            $this->language->myShowcaseAdminSummaryEditModeratorPermissionsAddUserUsername . ' <em>*</em>',
            $this->language->myShowcaseAdminSummaryEditModeratorPermissionsAddUserUsernameDescription,
            $form->generate_text_box(
                'showcase[username]',
                '',
                ['id' => 'username']
            ),
            'username'
        );

        $form_container->end();

        // Autocompletion for usernames
        echo '
		<link rel="stylesheet" href="../jscripts/select2/select2.css">
		<script type="text/javascript" src="../jscripts/select2/select2.min.js?ver=1804"></script>
		<script type="text/javascript">
		<!--
		$("#username").select2({
			placeholder: "' . $this->language->search_for_a_user . '",
			minimumInputLength: 2,
			multiple: false,
			ajax: { // instead of writing the function to execute the request we use Select2\'s convenient helper
				url: "../xmlhttp.php?action=get_users",
				dataType: \'json\',
				data: function (term, page) {
					return {
						query: term, // search_field term
					};
				},
				results: function (data, page) { // parse the results into the format expected by Select2.
					// since we are using custom formatting functions we do not need to alter remote JSON data
					return {results: data};
				}
			},
			initSelection: function(element, callback) {
				var query = $(element).val();
				if (query !== "") {
					$.ajax("../xmlhttp.php?action=get_users&getone=1", {
						data: {
							query: query
						},
						dataType: "json"
					}).done(function(data) { callback(data); });
				}
			},
		});

		$(\'[for=username]\').on(\'click\', function(){
			$("#username").select2(\'open\');
			return false;
		});
		// -->
		</script>';

        $form->output_submit_wrapper(
            [$form->generate_submit_button($this->language->myShowcaseAdminButtonSubmitAddModeratorUser)]
        );

        $form->end();

        echo "</div>\n";

        hooksRun('admin_summary_add_edit_end');

        $this->page->output_footer();
    }

    private function showcaseTableCreateRebuild(): void
    {
        $createTable = $this->mybb->get_input('action') === 'tableCreate';

        $showcaseData = showcaseGet(["showcase_id='{$this->showcase_id}'"], ['field_set_id'], ['limit' => 1]);

        if (!$showcaseData || $createTable && showcaseDataTableExists($this->showcase_id)) {
            flash_message($this->language->myShowcaseAdminErrorInvalidShowcase, 'error');

            admin_redirect($this->url->build());
        }

        $dataTableStructure = dataTableStructureGet($this->showcase_id);

        hooksRun('admin_summary_table_create_rebuild_start');

        dbVerifyTables(["myshowcase_data{$this->showcase_id}" => $dataTableStructure]);

        if (showcaseDataTableExists($this->showcase_id)) {
            log_admin_action(['showcase_id' => $this->showcase_id, 'createTable' => $createTable]);

            if ($createTable) {
                flash_message($this->language->myShowcaseAdminSuccessTableCreated, 'success');
            } else {
                flash_message($this->language->myShowcaseAdminSuccessTableRebuilt, 'success');
            }
        } else {
            flash_message($this->language->myShowcaseAdminErrorTableCreate, 'error');
        }

        admin_redirect($this->url->build());
    }

    private function showcaseTableDrop(): void
    {
        $showcaseData = showcaseGet(["showcase_id='{$this->showcase_id}'"], ['name'], ['limit' => 1]);

        if (!$showcaseData) {
            flash_message($this->language->myShowcaseAdminErrorInvalidShowcase, 'error');

            admin_redirect($this->url->build());
        }

        hooksRun('admin_summary_table_drop_start');

        $showcaseName = $showcaseData['name'];

        $showcase = new Showcase($this->database, $this->mybb, $this->language, showcase_id: $this->showcase_id);

        if ($showcase->entries_repository->queryEntries()) {
            flash_message($this->language->myShowcaseAdminErrorTableDrop, 'error');

            admin_redirect($this->url->build());
        }

        if ($this->mybb->request_method === 'post') {
            if (!verify_post_check($this->mybb->get_input('my_post_key'), true)) {
                flash_message($this->language->myShowcaseAdminErrorInvalidPostKey, 'error');

                admin_redirect($this->url->build());
            }

            if (isset($this->mybb->input['no'])) {
                admin_redirect($this->url->build());
            }

            hooksRun('admin_summary_table_drop_post');

            if (showcaseDataTableExists($this->showcase_id)) {
                showcaseDataTableDrop($this->showcase_id);

                $showcase->showcase_repository->updateShowcase(['is_enabled' => 0]);
            }

            flash_message($this->language->myShowcaseAdminSuccessTableDropped, 'success');

            admin_redirect($this->url->build());
        }

        $this->page->output_confirm_action(
            $this->url->build(['action' => 'tableDrop', 'showcase_id' => $this->showcase_id]),
            $this->language->sprintf($this->language->myShowcaseAdminConfirmTableDrop, $showcaseName)
        );
    }

    private function showcaseDelete(): void
    {
        $showcaseData = showcaseGet(["showcase_id='{$this->showcase_id}'"], [], ['limit' => 1]);

        if (!$showcaseData) {
            flash_message($this->language->myShowcaseAdminErrorInvalidShowcase, 'error');

            admin_redirect($this->url->build());
        }

        hooksRun('admin_summary_delete_start');

        $showcaseName = $showcaseData['name'];

        if ($this->mybb->request_method === 'post') {
            if (!verify_post_check($this->mybb->get_input('my_post_key'), true)) {
                flash_message($this->language->myShowcaseAdminErrorInvalidPostKey, 'error');

                admin_redirect($this->url->build());
            }

            if (isset($this->mybb->input['no'])) {
                admin_redirect($this->url->build());
            }

            hooksRun('admin_summary_delete_post');

            showcaseDelete($this->showcase_id);

            $this->cache_manager->update(CacheDataTypes::Config);

            $this->cache_manager->update(CacheDataTypes::Permissions);

            log_admin_action(['showcase_id' => $this->showcase_id]);

            if (showcaseGet(["showcase_id='{$this->showcase_id}'"], [], ['limit' => 1])) {
                flash_message($this->language->myShowcaseAdminErrorShowcaseDelete, 'error');
            } else {
                flash_message($this->language->myShowcaseAdminSuccessShowcaseDeleted, 'success');
            }

            admin_redirect($this->url->build());
        }

        $this->page->output_confirm_action(
            $this->url->build(['action' => 'delete', 'showcase_id' => $this->showcase_id]),
            $this->language->sprintf($this->language->myShowcaseAdminConfirmShowcaseDelete, $showcaseName)
        );
    }

    private function showcaseMain(): void
    {
        hooksRun('admin_summary_main_start');

        $this->page->output_header($this->language->myShowcaseAdminSummary);

        $this->page->output_nav_tabs($this->tabs, 'myShowcaseAdminSummary');

        $form_container = new FormContainer($this->language->myshowcase_summary_existing);

        $form_container->output_row_header(
            $this->language->myshowcase_summary_id,
            ['width' => '2%', 'class' => 'align_center']
        );

        $form_container->output_row_header(
            $this->language->myshowcase_summary_name,
            ['width' => '10%', 'class' => 'align_center']
        );

        $form_container->output_row_header(
            $this->language->myshowcase_summary_entries_count,
            ['width' => '5%', 'class' => 'align_center']
        );

        $form_container->output_row_header(
            $this->language->myshowcase_summary_comment_count,
            ['width' => '5%', 'class' => 'align_center']
        );

        $form_container->output_row_header(
            $this->language->myshowcase_summary_attachments_count,
            ['width' => '5%', 'class' => 'align_center']
        );

        $form_container->output_row_header(
            $this->language->myshowcase_summary_attachments_size,
            ['width' => '5%', 'class' => 'align_center']
        );

        $form_container->output_row_header(
            $this->language->myshowcase_summary_field_set,
            ['width' => '5%', 'class' => 'align_center']
        );

        $form_container->output_row_header(
            $this->language->myshowcase_summary_status,
            ['width' => '2%', 'class' => 'align_center']
        );

        $form_container->output_row_header($this->language->controls, ['width' => '5%', 'class' => 'align_center']);

        $showcaseObjects = showcaseGet([],
            array_keys(TABLES_DATA['myshowcase_config']),
            ['order_by' => 'display_order']);

        if (!$showcaseObjects) {
            $form_container->output_cell($this->language->myshowcase_summary_no_myshowcases, ['colspan' => 9]);

            $form_container->construct_row();
        } else {
            foreach ($showcaseObjects as $showcase_id => $showcaseData) {
                $showcase = new Showcase($this->database, $this->mybb, $this->language, showcase_id: $showcase_id);

                $showcaseTotalEntries = $showcaseTotalAttachments = $showcaseTotalFilesSize = $showcaseTotalComments = 0;

                $fieldsetID = (int)$showcaseData['field_set_id'];

                if ($showcase->data_table_exists) {
                    $showcaseTotalEntries = $showcase->entries_repository->queryEntryCount();

                    $showcaseTotalAttachments = $showcase->attachments_repository->queryAttachmentCount();

                    $showcaseTotalFilesSize = $showcase->attachments_repository->queryAttachmentSum();

                    $showcaseTotalComments = $showcase->comments_repository->queryCommentCount();
                }

                // Build popup menu
                $popup = new PopupMenu("myshowcase_{$showcase_id}", $this->language->options);

                $popup->add_item(
                    $this->language->myshowcase_summary_edit,
                    $this->url->build(['action' => 'update', 'showcase_id' => $showcase_id])
                );

                //grab status images at same time
                if (!empty($showcaseData['is_enabled'])) {
                    $statusImage = "styles/{$this->page->style}/images/icons/bullet_on.png";

                    $statusText = $this->language->myshowcase_summary_status_enabled;
                } else {
                    $statusImage = "styles/{$this->page->style}/images/icons/bullet_off.png";

                    $statusText = $this->language->myshowcase_summary_status_disabled;
                }

                //override status if table does not exist
                if (!$showcase->data_table_exists) {
                    $statusImage = "styles/{$this->page->style}/images/icons/error.png";

                    $statusText = $this->language->myshowcase_summary_status_notable;

                    $popup->add_item(
                        $this->language->myshowcase_summary_createtable,
                        $this->url->build(['action' => 'tableCreate', 'showcase_id' => $showcase_id])
                    );
                } else //create delete table popup item
                {
                    $popup->add_item(
                        $this->language->myshowcase_summary_rebuildtable,
                        $this->url->build(['action' => 'tableRebuild', 'showcase_id' => $showcase_id])
                    );

                    $popup->add_item(
                        $this->language->myshowcase_summary_deletetable,
                        $this->url->build(['action' => 'tableDrop', 'showcase_id' => $showcase_id])
                    );
                }

                $popup->add_item(
                    $this->language->myshowcase_summary_seo,
                    $this->url->build(['action' => 'viewRewrites', 'showcase_id' => $showcaseData['showcase_id']])
                );

                $popup->add_item(
                    $this->language->myshowcase_summary_delete,
                    $this->url->build(['action' => 'delete', 'showcase_id' => $showcase_id])
                );

                $showcaseData['attachments_uploads_path'] = $showcaseData['attachments_uploads_path'] ?? $this->language->myshowcase_summary_not_specified;

                $form_container->output_cell($showcase_id, ['class' => 'align_center']);

                $form_container->output_cell($showcaseData['name']);

                $form_container->output_cell($showcaseTotalEntries, ['class' => 'align_center']);

                $form_container->output_cell($showcaseTotalComments, ['class' => 'align_center']);

                $form_container->output_cell($showcaseTotalAttachments, ['class' => 'align_center']);

                $form_container->output_cell(
                    get_friendly_size($showcaseTotalFilesSize),
                    ['class' => 'align_center']
                );

                $form_container->output_cell(
                    fieldsetGet(
                        ["set_id='{$fieldsetID}'"],
                        ['set_name'],
                        ['limit' => 1]
                    )['set_name'] . ' (ID=' . $fieldsetID . ')',
                    ['class' => 'align_center']
                );

                $form_container->output_cell(
                    '<img src="' . $statusImage . '" title="' . $statusText . '">',
                    ['class' => 'align_center']
                );

                $form_container->output_cell($popup->fetch(), ['class' => 'align_center']);

                $form_container->construct_row();
            }
        }

        $form_container->end();

        hooksRun('admin_summary_main_end');

        $this->page->output_footer();
    }
}