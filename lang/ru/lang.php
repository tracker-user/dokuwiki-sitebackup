<?php

$lang['menu'] = 'Резервная копия сайта';

// Страница администратора
$lang['intro']      = 'Выберите компоненты для включения, нажмите <em>Предпросмотр</em>, чтобы увидеть список файлов и общий объём, а затем нажмите <em>Скачать tar.gz</em> для получения архива.';
$lang['warn_title'] = 'Внимание: конфиденциальные данные.';
$lang['warn_body']  = 'Архив может содержать хеши паролей (<code>conf/users.auth.php</code>), правила ACL и учётные данные из <code>conf/local.php</code> (строки подключения к БД, пароли SMTP, ключи API). Обращайтесь с загруженным файлом как с конфиденциальными учётными данными.';

// Группы полей
$lang['fs_content'] = 'Содержимое вики';
$lang['fs_code']    = 'Конфигурация и код';

// Флажки
$lang['opt_pages']       = 'Страницы (data/pages)';
$lang['opt_media']       = 'Медиафайлы (data/media)';
$lang['opt_meta']        = 'Метаданные страниц (data/meta)';
$lang['opt_media_meta']  = 'Метаданные медиафайлов (data/media_meta)';
$lang['opt_attic']       = 'Ревизии страниц (data/attic) — может быть большим';
$lang['opt_media_attic'] = 'Ревизии медиафайлов (data/media_attic)';
$lang['opt_index']       = 'Поисковый индекс (data/index) — можно перестроить';
$lang['opt_conf']        = 'Конфигурация (conf/) — содержит секреты';
$lang['opt_plugins']     = 'Исходный код плагинов (lib/plugins/)';
$lang['opt_tpl']         = 'Исходный код шаблонов (lib/tpl/)';

// Кнопки
$lang['btn_preview']  = 'Предпросмотр';
$lang['btn_download'] = 'Скачать tar.gz';

// Раздел предпросмотра
$lang['preview_head']    = 'Предпросмотр';
$lang['preview_summary'] = '%d файлов, %s в несжатом виде.';
$lang['col_section']     = 'Раздел';
$lang['col_files']       = 'Файлов';
$lang['col_size']        = 'Размер';
$lang['preview_hint']    = 'Нажмите <em>Скачать tar.gz</em> выше, чтобы создать и загрузить архив (сжатый размер, как правило, будет меньше).';

// Сообщения об ошибках
$lang['err_post']    = 'Site Backup: загрузка должна выполняться через POST.';
$lang['err_admin']   = 'Site Backup: необходим доступ администратора.';
$lang['err_empty']   = 'Site Backup: ничего не выбрано.';
$lang['err_tmp']     = 'Site Backup: временная директория недоступна для записи: %s';
$lang['err_create']  = 'Site Backup: не удалось создать архив: %s';
$lang['err_archive'] = 'Site Backup: архив оказался пустым или не был записан.';
