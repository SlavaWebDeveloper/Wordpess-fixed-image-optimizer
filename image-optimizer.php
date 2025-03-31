<?php
/**
 * Plugin Name: Fixed Image Optimizer
 * Description: Оптимизирует изображения и конвертирует в WebP без лицензии. Работает во всех местах WordPress с сохранением в БД.
 * Version: 1.3
 * Author: Slava Sidorov (Improved)
 */

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

class Fixed_Image_Optimizer
{
  // Мета-ключи для хранения информации об оптимизированных изображениях
  const OPTIMIZED_IMAGE_META_KEY = '_fixed_optimized_image_id';
  const OPTIMIZATION_DATA_META_KEY = '_fixed_optimization_data';

  public function __construct()
  {
    // Инициализация хуков
    add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

    // Добавляем только в медиа-мета, убираем из полей редактирования
    add_filter('media_meta', array($this, 'add_optimize_to_media_meta'), 10, 2);
    add_action('admin_footer', array($this, 'add_js_to_media_library'));

    // AJAX обработчик
    add_action('wp_ajax_fixed_optimize_image', array($this, 'ajax_optimize_image'));

    // Создаем CSS и JS при активации плагина
    register_activation_hook(__FILE__, array($this, 'create_plugin_files'));

    // Проверяем наличие файлов при инициализации
    add_action('admin_init', array($this, 'check_plugin_files'));

    // Добавляем фильтр для отображения иконки оптимизированного изображения
    add_filter('attachment_fields_to_edit', array($this, 'add_optimization_badge'), 10, 2);

    // Обработка удаления вложения для очистки связанных оптимизированных изображений
    add_action('delete_attachment', array($this, 'delete_optimized_attachments'));
  }

  // Подключение скриптов и стилей
  public function enqueue_scripts()
  {
    $css_path = plugin_dir_url(__FILE__) . 'fixed-style.css';
    $js_path = plugin_dir_url(__FILE__) . 'fixed-script.js';

    // Добавляем версию для предотвращения кеширования
    $version = filemtime(plugin_dir_path(__FILE__) . 'fixed-style.css');

    wp_enqueue_style('fixed-optimizer-style', $css_path, array(), $version);
    wp_enqueue_script('fixed-optimizer-script', $js_path, array('jquery'), $version, true);

    wp_localize_script('fixed-optimizer-script', 'fixedOptimizerData', array(
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('fixed_optimizer_nonce')
    ));
  }

  // Проверка наличия файлов и создание при необходимости
  public function check_plugin_files()
  {
    $css_path = plugin_dir_path(__FILE__) . 'fixed-style.css';
    $js_path = plugin_dir_path(__FILE__) . 'fixed-script.js';

    if (!file_exists($css_path) || !file_exists($js_path)) {
      $this->create_plugin_files();
    }
  }

  // Создание файлов стилей и скриптов
  public function create_plugin_files()
  {
    $css_path = plugin_dir_path(__FILE__) . 'fixed-style.css';
    $js_path = plugin_dir_path(__FILE__) . 'fixed-script.js';

    // CSS содержимое с добавлением стилей для значка оптимизации
    $css_content = '.fixed-optimize-button {
    margin-top: 5px !important;
    background: #0085ba !important;
    color: white !important;
    border-color: #0073aa !important;
    font-weight: bold !important;
}
.fixed-optimize-result {
    margin-top: 8px;
    padding: 5px;
    background-color: #f8f9fa;
    border-left: 4px solid #46b450;
    display: none;
}
.fixed-quality-slider {
    width: 80%;
    vertical-align: middle;
}
.attachments-browser .attachment-details .fixed-optimize-container {
    padding: 5px 16px;
    border-top: 1px solid #ddd;
    margin-top: 10px;
}
.attachment-details .fixed-optimize-container h3 {
    font-weight: 600;
    margin: 10px 0 5px;
}
.attachment-info .fixed-optimize-container {
    padding: 5px 0;
    border-top: 1px solid #ddd;
    margin-top: 10px;
}
.fixed-refresh-button {
    margin-left: 5px !important;
    background: #0073aa !important;
    color: white !important;
    border-color: #006799 !important;
    font-weight: bold !important;
}
.fixed-format-select {
    margin-bottom: 10px;
    width: 100%;
}
.fixed-optimization-badge {
    position: absolute;
    top: 5px;
    right: 5px;
    background: #46b450;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    text-align: center;
    line-height: 20px;
    font-size: 12px;
    z-index: 10;
}
.fixed-optimization-info {
    margin-top: 5px;
    padding: 5px;
    background-color: #f0f8ff;
    border-left: 4px solid #0085ba;
}';

    // JS содержимое - с обновлением кода для проверки существующих оптимизированных изображений
    $js_content = 'jQuery(document).ready(function($) {
    // Обновление отображения процента качества
    $(document).on("input", ".fixed-quality-slider", function() {
        var id = $(this).data("id");
        $("#fixed-quality-value-" + id).text($(this).val() + "%");
    });
    
    // Обработка клика по кнопке оптимизации
    $(document).on("click", ".fixed-optimize-button", function() {
        var button = $(this);
        var id = button.data("id");
        var quality = $("#fixed-quality-slider-" + id).val();
        var format = $("#fixed-format-select-" + id).val();
        var resultBox = $("#fixed-optimize-result-" + id);
        
        // Проверяем, есть ли у нас информация о существующем оптимизированном изображении
        var hasExisting = button.data("has-optimized") === "yes";
        
        // Отключаем кнопку и показываем, что идет процесс
        button.prop("disabled", true).text("Оптимизация...");
        
        // Отправляем запрос на оптимизацию
        $.ajax({
            url: fixedOptimizerData.ajaxUrl,
            type: "POST",
            data: {
                action: "fixed_optimize_image",
                id: id,
                quality: quality,
                format: format,
                nonce: fixedOptimizerData.nonce,
                update_existing: hasExisting
            },
            success: function(response) {
                button.prop("disabled", false).text("Оптимизировать");
                
                if (response.success) {
                    resultBox.html(response.data.message).css("border-left-color", "#46b450").show();
                    
                    // Обновляем статус оптимизации
                    button.data("has-optimized", "yes");
                    
                    // Добавляем кнопку обновления страницы
                    if (!$("#fixed-refresh-button-" + id).length) {
                        var refreshButton = $("<button>")
                            .attr("type", "button")
                            .attr("id", "fixed-refresh-button-" + id)
                            .addClass("button fixed-refresh-button")
                            .text("Обновить страницу")
                            .on("click", function() {
                                window.location.reload();
                            });
                        button.after(refreshButton);
                    }
                    
                    // Обновляем отображение оптимизационной информации
                    if (response.data.optimization_info) {
                        if ($("#fixed-optimization-info-" + id).length) {
                            $("#fixed-optimization-info-" + id).html(response.data.optimization_info);
                        } else {
                            $("<div>")
                                .attr("id", "fixed-optimization-info-" + id)
                                .addClass("fixed-optimization-info")
                                .html(response.data.optimization_info)
                                .insertBefore(resultBox);
                        }
                    }
                    
                    // Обновляем отображение изображения в медиа библиотеке
                    if (response.data.attachment_id && response.data.image_url) {
                        // Обновляем предпросмотр изображения, если находимся на странице медиа
                        var $thumbnail = $(".attachment[data-id=\'" + response.data.original_id + "\'] .thumbnail");
                        if ($thumbnail.length) {
                            // Добавляем timestamp для избежания кеширования
                            var randomParam = "?t=" + new Date().getTime();
                            
                            // Если у нас есть бейдж оптимизации, обновляем его, иначе добавляем
                            if (!$thumbnail.find(".fixed-optimization-badge").length) {
                                $thumbnail.append("<div class=\'fixed-optimization-badge\' title=\'Оптимизировано\'>✓</div>");
                            }
                        }
                        
                        // Обновляем медиатеку
                        refreshMediaLibrary(response.data.original_id);
                    }
                } else {
                    resultBox.html("Ошибка: " + response.data).css("border-left-color", "#dc3232").show();
                }
            },
            error: function() {
                button.prop("disabled", false).text("Оптимизировать");
                resultBox.html("Ошибка соединения").css("border-left-color", "#dc3232").show();
            }
        });
    });
    
    // Функция для обновления медиатеки
    function refreshMediaLibrary(attachmentId) {
        // Если мы находимся в медиа библиотеке, обновляем содержимое
        if (wp.media && wp.media.frame) {
            // Используем WordPress Media API для обновления библиотеки
            var selection = wp.media.frame.state().get("selection");
            var library = wp.media.frame.state().get("library");
            
            // Обновляем коллекцию библиотеки
            if (library) {
                // Принудительное обновление коллекции
                library.more(true).reset();
                
                // Если у нас есть ID вложения, ждем пока библиотека загрузится и выбираем его
                if (attachmentId) {
                    var checkLibrary = setInterval(function() {
                        var attachment = wp.media.attachment(attachmentId);
                        if (attachment.id) {
                            clearInterval(checkLibrary);
                            
                            // Выделение элемента
                            selection.reset([attachment]);
                            
                            // Прокручиваем к элементу
                            setTimeout(function() {
                                var $attachment = $(".attachment[data-id=\'" + attachmentId + "\']");
                                if ($attachment.length) {
                                    $attachment.get(0).scrollIntoView();
                                    // Добавляем подсветку для элемента
                                    $attachment.addClass("highlighted");
                                    setTimeout(function() {
                                        $attachment.removeClass("highlighted");
                                    }, 2000);
                                }
                            }, 300);
                        }
                    }, 200);
                }
            }
        }
    }
    
    // Добавление элементов в модальное окно медиабиблиотеки
    function addOptimizerToMediaModal() {
        $(".attachment-details").each(function() {
            var $this = $(this);
            var id = $this.find(".attachment-id").val();
            
            if (!id || $this.find(".fixed-optimize-container").length > 0) {
                return;
            }
            
            // Получаем данные об оптимизации из атрибутов data
            var hasOptimized = $this.find(".details-image").data("has-optimized") === "yes";
            var optimizationInfo = $this.find(".details-image").data("optimization-info") || "";
            
            var container = $("<div class=\'fixed-optimize-container\'>" +
                "<h3>Оптимизация изображения</h3>");
            
            // Если есть информация об оптимизации, добавляем ее
            if (hasOptimized && optimizationInfo) {
                container.append("<div id=\'fixed-optimization-info-" + id + "\' class=\'fixed-optimization-info\'>" + optimizationInfo + "</div>");
            }
            
            container.append("<div>" +
                "<select class=\'fixed-format-select\' id=\'fixed-format-select-" + id + "\'>" +
                "<option value=\'webp\'>WebP формат</option>" +
                "<option value=\'original\'>Оригинальный формат</option>" +
                "</select>" +
                "</div>" +
                "<div>" +
                "<label>Качество: <span id=\'fixed-quality-value-" + id + "\'>80%</span></label>" +
                "<input type=\'range\' class=\'fixed-quality-slider\' id=\'fixed-quality-slider-" + id + "\' data-id=\'" + id + "\' min=\'1\' max=\'100\' value=\'80\' step=\'1\'>" +
                "</div>" +
                "<div>" +
                "<button type=\'button\' class=\'button fixed-optimize-button\' data-id=\'" + id + "\' data-has-optimized=\'" + (hasOptimized ? "yes" : "no") + "\'>" + 
                (hasOptimized ? "Переоптимизировать" : "Оптимизировать") + "</button>" +
                "<div id=\'fixed-optimize-result-" + id + "\' class=\'fixed-optimize-result\'></div>" +
                "</div>");
            
            $this.find(".attachment-info").append(container);
        });
    }
    
    // Наблюдение за изменениями в DOM для модального окна медиабиблиотеки
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                addOptimizerToMediaModal();
            }
        });
    });
    
    // Запуск наблюдения
    if (document.querySelector(".media-frame")) {
        observer.observe(document.querySelector(".media-frame"), {
            childList: true,
            subtree: true
        });
    }
    
    // Первичное добавление при загрузке страницы
    addOptimizerToMediaModal();
    
    // Для режима сетки в медиабиблиотеке
    $(document).on("click", ".attachment", function() {
        setTimeout(addOptimizerToMediaModal, 100);
    });
    
    // Добавляем стиль для подсветки элемента
    $("<style>.attachment.highlighted { box-shadow: 0 0 0 3px #0085ba !important; transition: box-shadow 0.3s ease-in-out; }</style>").appendTo("head");
});';

    // Записываем содержимое в файлы
    if (!file_put_contents($css_path, $css_content)) {
      error_log('Failed to write CSS file for Fixed Image Optimizer plugin');
    }

    if (!file_put_contents($js_path, $js_content)) {
      error_log('Failed to write JS file for Fixed Image Optimizer plugin');
    }
  }

  // Добавление кнопки оптимизации на страницу информации о вложении
  public function add_optimize_to_media_meta($meta, $post)
  {
    if (!wp_attachment_is_image($post->ID)) {
      return $meta;
    }

    $image_path = get_attached_file($post->ID);
    $image_type = wp_check_filetype($image_path);

    $supported_formats = array('image/jpeg', 'image/png', 'image/jpg', 'image/webp');
    if (!in_array($image_type['type'], $supported_formats)) {
      return $meta;
    }

    // Проверяем, есть ли уже оптимизированная версия
    $optimized_id = get_post_meta($post->ID, self::OPTIMIZED_IMAGE_META_KEY, true);
    $has_optimized = !empty($optimized_id) && get_post($optimized_id);
    $optimization_data = get_post_meta($post->ID, self::OPTIMIZATION_DATA_META_KEY, true);

    $optimization_info = '';
    if ($has_optimized && !empty($optimization_data)) {
      $format = isset($optimization_data['format']) ? esc_html($optimization_data['format']) : 'unknown';
      $quality = isset($optimization_data['quality']) ? intval($optimization_data['quality']) : 0;
      $saved_percent = isset($optimization_data['saved_percent']) ? intval($optimization_data['saved_percent']) : 0;
      $dimensions = isset($optimization_data['dimensions']) ? esc_html($optimization_data['dimensions']) : '';

      $optimized_url = wp_get_attachment_url($optimized_id);

      $optimization_info = sprintf(
        'Формат: %s, Качество: %d%%, Экономия: %d%%. <a href="%s" target="_blank">Просмотреть</a>',
        $format,
        $quality,
        $saved_percent,
        $dimensions,
        esc_url($optimized_url)
      );
    }

    $optimizer_html = '<div class="fixed-optimize-container">';
    $optimizer_html .= '<h3>Оптимизация изображения</h3>';

    if ($has_optimized) {
      $optimizer_html .= '<div id="fixed-optimization-info-' . esc_attr($post->ID) . '" class="fixed-optimization-info">' . $optimization_info . '</div>';
    }

    $optimizer_html .= '<div>';
    $optimizer_html .= '<select class="fixed-format-select" id="fixed-format-select-' . esc_attr($post->ID) . '">';
    $optimizer_html .= '<option value="webp">WebP формат</option>';
    $optimizer_html .= '<option value="original">Оригинальный формат</option>';
    $optimizer_html .= '</select>';
    $optimizer_html .= '</div>';
    $optimizer_html .= '<div><label>Качество: <span id="fixed-quality-value-' . esc_attr($post->ID) . '">80%</span></label>';
    $optimizer_html .= '<input type="range" class="fixed-quality-slider" id="fixed-quality-slider-' . esc_attr($post->ID) . '" data-id="' . esc_attr($post->ID) . '" min="1" max="100" value="80" step="1"></div>';
    $optimizer_html .= '<div><button type="button" class="button fixed-optimize-button" data-id="' . esc_attr($post->ID) . '" data-has-optimized="' . ($has_optimized ? 'yes' : 'no') . '">' . ($has_optimized ? 'Переоптимизировать' : 'Оптимизировать') . '</button>';
    $optimizer_html .= '<div id="fixed-optimize-result-' . esc_attr($post->ID) . '" class="fixed-optimize-result"></div></div>';
    $optimizer_html .= '</div>';

    return $meta . $optimizer_html;
  }

  // Добавление JS для внедрения кнопки в модальное окно медиабиблиотеки
  public function add_js_to_media_library()
  {
    $screen = get_current_screen();

    if (!$screen || $screen->base !== 'upload') {
      return;
    }

    ?>
    <script type='text/javascript'>
      jQuery(document).ready(function ($) {
        // Функция добавления интерфейса оптимизатора
        function addOptimizerToAttachmentDetails() {
          $('.attachment-details .attachment-info').each(function () {
            var $this = $(this);
            var id = $this.closest('.attachment-details').find('.attachment-id').val();

            if (!id || $this.find('.fixed-optimize-container').length > 0) {
              return;
            }

            // Получаем информацию о том, есть ли уже оптимизированное изображение
            var hasOptimized = $this.closest('.attachment-details').find('.details-image').data('has-optimized') === 'yes';
            var optimizationInfo = $this.closest('.attachment-details').find('.details-image').data('optimization-info') || '';

            var container = $('<div class="fixed-optimize-container">' +
              '<h3>Оптимизация изображения</h3>');

            // Если есть информация об оптимизации, добавляем ее
            if (hasOptimized && optimizationInfo) {
              container.append('<div id="fixed-optimization-info-' + id + '" class="fixed-optimization-info">' + optimizationInfo + '</div>');
            }

            container.append('<div>' +
              '<select class="fixed-format-select" id="fixed-format-select-' + id + '">' +
              '<option value="webp">WebP формат</option>' +
              '<option value="original">Оригинальный формат</option>' +
              '</select>' +
              '</div>' +
              '<div>' +
              '<label>Качество: <span id="fixed-quality-value-' + id + '">80%</span></label>' +
              '<input type="range" class="fixed-quality-slider" id="fixed-quality-slider-' + id + '" data-id="' + id + '" min="1" max="100" value="80" step="1">' +
              '</div>' +
              '<div>' +
              '<button type="button" class="button fixed-optimize-button" data-id="' + id + '" data-has-optimized="' + (hasOptimized ? 'yes' : 'no') + '">' +
              (hasOptimized ? 'Переоптимизировать' : 'Оптимизировать') + '</button>' +
              '<div id="fixed-optimize-result-' + id + '" class="fixed-optimize-result"></div>' +
              '</div>');

            $this.append(container);
          });
        }

        // Обработка события выбора вложения
        $(document).on('click', '.attachment', function () {
          setTimeout(addOptimizerToAttachmentDetails, 100);
        });

        // Обработка событий изменения в медиабиблиотеке
        var observer = new MutationObserver(function (mutations) {
          addOptimizerToAttachmentDetails();
        });

        if (document.querySelector('.media-frame')) {
          observer.observe(document.querySelector('.media-frame'), {
            childList: true,
            subtree: true
          });
        }

        // Первичное добавление при загрузке страницы
        addOptimizerToAttachmentDetails();
      });
    </script>
    <?php
  }

  // Добавление значка оптимизации к изображениям в медиа-библиотеке
  public function add_optimization_badge($form_fields, $post)
  {
    if (wp_attachment_is_image($post->ID)) {
      $optimized_id = get_post_meta($post->ID, self::OPTIMIZED_IMAGE_META_KEY, true);
      if (!empty($optimized_id) && get_post($optimized_id)) {
        // Получаем данные оптимизации
        $optimization_data = get_post_meta($post->ID, self::OPTIMIZATION_DATA_META_KEY, true);
        $optimization_info = '';

        if (!empty($optimization_data)) {
          $format = isset($optimization_data['format']) ? esc_html($optimization_data['format']) : 'unknown';
          $quality = isset($optimization_data['quality']) ? intval($optimization_data['quality']) : 0;
          $saved_percent = isset($optimization_data['saved_percent']) ? intval($optimization_data['saved_percent']) : 0;
          $dimensions = isset($optimization_data['dimensions']) ? esc_html($optimization_data['dimensions']) : '';

          $optimization_info = sprintf(
            'Формат: %s, Качество: %d%%, Экономия: %d%%',
            $format,
            $quality,
            $saved_percent,
            $dimensions
          );
        }

        // Добавляем данные для JavaScript
        add_action('admin_footer', function () use ($post, $optimization_info) {
          ?>
          <script>
            jQuery(document).ready(function ($) {
              // Добавляем атрибуты data к изображению
              $('.attachment[data-id="<?php echo esc_js($post->ID); ?>"] .thumbnail').append('<div class="fixed-optimization-badge" title="Оптимизировано">✓</div>');
              $('.attachment-details[data-id="<?php echo esc_js($post->ID); ?>"] .details-image, .attachment[data-id="<?php echo esc_js($post->ID); ?>"] .thumbnail img').attr({
                'data-has-optimized': 'yes',
                'data-optimization-info': '<?php echo esc_js($optimization_info); ?>'
              });
            });
          </script>
          <?php
        });

        // Добавляем информацию об оптимизации в поля формы
        $form_fields['fixed_optimized'] = array(
          'label' => 'Оптимизировано',
          'input' => 'html',
          'html' => '<span style="color: #46b450;">✓ ' . esc_html($optimization_info) . '</span>'
        );
      }
    }
    return $form_fields;
  }

  // Обработка удаления вложения для очистки связанных оптимизированных изображений
  public function delete_optimized_attachments($post_id)
  {
    // Получаем ID оптимизированного изображения
    $optimized_id = get_post_meta($post_id, self::OPTIMIZED_IMAGE_META_KEY, true);

    // Если существует оптимизированное изображение, удаляем его
    if (!empty($optimized_id)) {
      wp_delete_attachment($optimized_id, true);
    }
  }

  // Обработка AJAX запроса на оптимизацию
  public function ajax_optimize_image()
  {
    // Проверка безопасности
    check_ajax_referer('fixed_optimizer_nonce', 'nonce');

    if (!current_user_can('upload_files')) {
      wp_send_json_error('Недостаточно прав');
    }

    $attachment_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $quality = isset($_POST['quality']) ? intval($_POST['quality']) : 80;
    $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'webp';
    $update_existing = isset($_POST['update_existing']) && $_POST['update_existing'] === 'yes';

    // Ограничиваем качество в рамках допустимого диапазона
    $quality = max(1, min(100, $quality));

    if (empty($attachment_id)) {
      wp_send_json_error('ID изображения не указан');
    }

    // Получаем информацию о изображении
    $image_path = get_attached_file($attachment_id);

    if (empty($image_path) || !file_exists($image_path)) {
      wp_send_json_error('Файл изображения не найден');
    }

    // Проверяем GD
    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagewebp')) {
      wp_send_json_error('Библиотека GD не найдена или не поддерживает нужные функции');
    }

    // Получаем существующее оптимизированное изображение, если есть
    $existing_optimized_id = get_post_meta($attachment_id, self::OPTIMIZED_IMAGE_META_KEY, true);

    // Если нам нужно обновить существующее, сначала удаляем старое
    if ($update_existing && !empty($existing_optimized_id)) {
      wp_delete_attachment($existing_optimized_id, true);
    }

    // Загружаем изображение в зависимости от его типа
    $image_type = exif_imagetype($image_path);
    $image = null;

    switch ($image_type) {
      case IMAGETYPE_JPEG:
        $image = imagecreatefromjpeg($image_path);
        break;
      case IMAGETYPE_PNG:
        $image = imagecreatefrompng($image_path);
        // Сохраняем прозрачность для PNG
        imagealphablending($image, false);
        imagesavealpha($image, true);
        break;
      case IMAGETYPE_WEBP:
        $image = imagecreatefromwebp($image_path);
        break;
      default:
        wp_send_json_error('Неподдерживаемый тип изображения');
        break;
    }

    if (!$image) {
      wp_send_json_error('Не удалось загрузить изображение');
    }

    // Устанавливаем максимальные размеры
    $max_width = 1920;
    $max_height = 1080;

    // Получаем текущие размеры
    $original_width = imagesx($image);
    $original_height = imagesy($image);

    // Вычисляем новые размеры с сохранением пропорций
    $new_width = $original_width;
    $new_height = $original_height;

    // Изменяем размер, если изображение превышает допустимые размеры
    if ($original_width > $max_width || $original_height > $max_height) {
      // Вычисляем коэффициенты масштабирования
      $width_ratio = $max_width / $original_width;
      $height_ratio = $max_height / $original_height;

      // Выбираем наименьший коэффициент для сохранения пропорций
      $scale_ratio = min($width_ratio, $height_ratio);

      // Вычисляем новые размеры
      $new_width = round($original_width * $scale_ratio);
      $new_height = round($original_height * $scale_ratio);
    }

    // Создаем новое изображение с новыми размерами
    $resized_image = imagecreatetruecolor($new_width, $new_height);

    // Сохраняем прозрачность для PNG
    if ($image_type === IMAGETYPE_PNG) {
      imagealphablending($resized_image, false);
      imagesavealpha($resized_image, true);
      $transparent = imagecolorallocatealpha($resized_image, 255, 255, 255, 127);
      imagefilledrectangle($resized_image, 0, 0, $new_width, $new_height, $transparent);
    }

    // Выполняем изменение размера
    imagecopyresampled(
      $resized_image,
      $image,
      0,
      0,
      0,
      0,
      $new_width,
      $new_height,
      $original_width,
      $original_height
    );

    // Освобождаем память от оригинального изображения
    imagedestroy($image);

    // Используем измененное изображение для дальнейшей оптимизации
    $image = $resized_image;

    // Определяем путь к uploads директории
    $upload_dir = wp_upload_dir();
    $original_filename = basename($image_path);
    $filename_without_ext = pathinfo($original_filename, PATHINFO_FILENAME);

    // Определяем расширение файла в зависимости от выбранного формата
    $new_extension = ($format === 'webp') ? 'webp' : pathinfo($original_filename, PATHINFO_EXTENSION);
    $new_filename = $filename_without_ext . '-optimized.' . $new_extension;
    $new_filepath = $upload_dir['path'] . '/' . $new_filename;

    // Создаем директорию, если она не существует
    if (!file_exists($upload_dir['path'])) {
      wp_mkdir_p($upload_dir['path']);
    }

    // Сохраняем оптимизированное изображение
    $success = false;
    if ($format === 'webp') {
      $success = imagewebp($image, $new_filepath, $quality);
    } else {
      switch ($image_type) {
        case IMAGETYPE_JPEG:
          $success = imagejpeg($image, $new_filepath, $quality);
          break;
        case IMAGETYPE_PNG:
          // Для PNG качество от 0 до 9, где 0 - без сжатия, 9 - максимальное сжатие
          $png_quality = 9 - round($quality / 100 * 9);
          $success = imagepng($image, $new_filepath, $png_quality);
          break;
        case IMAGETYPE_WEBP:
          $success = imagewebp($image, $new_filepath, $quality);
          break;
      }
    }

    // Освобождаем память
    imagedestroy($image);

    if (!$success) {
      wp_send_json_error('Не удалось сохранить оптимизированное изображение');
    }

    // Получаем размер оригинального файла
    $original_size = filesize($image_path);

    // Получаем размер оптимизированного файла
    $optimized_size = filesize($new_filepath);

    // Вычисляем процент экономии
    $saved_percent = round(($original_size - $optimized_size) / $original_size * 100);

    // Добавляем изображение в медиа-библиотеку
    $attachment = array(
      'guid' => $upload_dir['url'] . '/' . $new_filename,
      'post_mime_type' => $format === 'webp' ? 'image/webp' : mime_content_type($new_filepath),
      'post_title' => $filename_without_ext . ' (оптимизировано)',
      'post_content' => '',
      'post_status' => 'inherit'
    );

    $optimized_id = wp_insert_attachment($attachment, $new_filepath);

    if (is_wp_error($optimized_id)) {
      @unlink($new_filepath);
      wp_send_json_error('Не удалось добавить оптимизированное изображение в медиа-библиотеку');
    }

    // Генерируем метаданные для вложения
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($optimized_id, $new_filepath);
    wp_update_attachment_metadata($optimized_id, $attach_data);

    // Сохраняем связь между оригинальным и оптимизированным изображениями
    update_post_meta($attachment_id, self::OPTIMIZED_IMAGE_META_KEY, $optimized_id);

    // Сохраняем информацию об оптимизации
    $optimization_data = array(
      'format' => $format === 'webp' ? 'WebP' : mime_content_type($new_filepath),
      'quality' => $quality,
      'saved_percent' => $saved_percent,
      'original_dimensions' => $original_width . 'x' . $original_height,
      'new_dimensions' => $new_width . 'x' . $new_height,
      'original_size' => size_format($original_size),
      'optimized_size' => size_format($optimized_size),
      'timestamp' => current_time('timestamp')
    );

    update_post_meta($attachment_id, self::OPTIMIZATION_DATA_META_KEY, $optimization_data);

    // Формируем информацию об оптимизации для отображения
    $optimization_info = sprintf(
      'Формат: %s, Качество: %d%%, Экономия: %d%%<br>Исходный размер: %s → Новый размер: %s<br>Размер файла: %s → %s',
      $optimization_data['format'],
      $quality,
      $saved_percent,
      $optimization_data['original_dimensions'],
      $optimization_data['new_dimensions'],
      $optimization_data['original_size'],
      $optimization_data['optimized_size']
    );

    // Получаем URL оптимизированного изображения
    $optimized_url = wp_get_attachment_url($optimized_id);

    // Возвращаем успешный результат
    wp_send_json_success(array(
      'message' => 'Изображение успешно оптимизировано и сохранено',
      'optimization_info' => $optimization_info,
      'optimized_url' => $optimized_url,
      'original_id' => $attachment_id,
      'attachment_id' => $optimized_id,
      'image_url' => $optimized_url
    ));
  }
}

// Инициализация плагина
new Fixed_Image_Optimizer();