<?php
/**
 * Plugin Name: Fixed Image Optimizer
 * Description: Оптимизирует изображения и конвертирует в WebP без лицензии. Работает во всех местах WordPress.
 * Version: 1.1
 * Author: Slava Sidorov
 */

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

class Fixed_Image_Optimizer
{

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

    // CSS содержимое
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
}';

    // JS содержимое - с обновлением и поддержкой WebP
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
                nonce: fixedOptimizerData.nonce
            },
            success: function(response) {
                button.prop("disabled", false).text("Оптимизировать");
                
                if (response.success) {
                    resultBox.html(response.data.message).css("border-left-color", "#46b450").show();
                    
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
                    
                    // Обновляем отображение изображения в медиа библиотеке
                    if (response.data.attachment_id && response.data.image_url) {
                        // Обновляем предпросмотр изображения, если находимся на странице медиа
                        var $thumbnail = $(".attachment[data-id=\'" + response.data.attachment_id + "\'] .thumbnail");
                        if ($thumbnail.length) {
                            // Добавляем timestamp для избежания кеширования
                            var randomParam = "?t=" + new Date().getTime();
                            $thumbnail.find("img").attr("src", response.data.image_url + randomParam);
                        }
                        
                        // Обновляем предпросмотр в деталях
                        var $detailImage = $(".attachment-details .details-image");
                        if ($detailImage.length) {
                            var randomParam = "?t=" + new Date().getTime();
                            $detailImage.attr("src", response.data.image_url + randomParam);
                        }
                        
                        // Обновляем медиатеку для отображения нового изображения
                        refreshMediaLibrary(response.data.attachment_id);
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
    
    // Функция для обновления медиатеки и отображения нового изображения
    function refreshMediaLibrary(newAttachmentId) {
        // Если мы находимся в медиа библиотеке, обновляем содержимое
        if (wp.media && wp.media.frame) {
            // Используем WordPress Media API для обновления библиотеки
            var selection = wp.media.frame.state().get("selection");
            var library = wp.media.frame.state().get("library");
            
            // Обновляем коллекцию библиотеки
            if (library) {
                // Принудительное обновление коллекции
                library.more(true).reset();
                
                // Если у нас есть ID нового вложения, ждем пока библиотека загрузится и выбираем его
                if (newAttachmentId) {
                    var checkLibrary = setInterval(function() {
                        var attachment = wp.media.attachment(newAttachmentId);
                        if (attachment.id) {
                            clearInterval(checkLibrary);
                            
                            // Выделение нового элемента
                            selection.reset([attachment]);
                            
                            // Прокручиваем к новому элементу
                            setTimeout(function() {
                                var $newAttachment = $(".attachment[data-id=\'" + newAttachmentId + "\']");
                                if ($newAttachment.length) {
                                    $newAttachment.get(0).scrollIntoView();
                                    // Добавляем подсветку для нового элемента
                                    $newAttachment.addClass("highlighted");
                                    setTimeout(function() {
                                        $newAttachment.removeClass("highlighted");
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
            
            $this.find(".attachment-info").append(
                "<div class=\"fixed-optimize-container\">" +
                    "<h3>Оптимизация изображения</h3>" +
                    "<div>" +
                        "<select class=\"fixed-format-select\" id=\"fixed-format-select-" + id + "\">" +
                            "<option value=\"webp\">WebP формат</option>" +
                            "<option value=\"original\">Оригинальный формат</option>" +
                        "</select>" +
                    "</div>" +
                    "<div>" +
                        "<label>Качество: <span id=\"fixed-quality-value-" + id + "\">80%</span></label>" +
                        "<input type=\"range\" class=\"fixed-quality-slider\" id=\"fixed-quality-slider-" + id + "\" data-id=\"" + id + "\" min=\"1\" max=\"100\" value=\"80\" step=\"1\">" +
                    "</div>" +
                    "<div>" +
                        "<button type=\"button\" class=\"button fixed-optimize-button\" data-id=\"" + id + "\">Оптимизировать</button>" +
                        "<div id=\"fixed-optimize-result-" + id + "\" class=\"fixed-optimize-result\"></div>" +
                    "</div>" +
                "</div>"
            );
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
    
    // Добавляем стиль для подсветки нового элемента
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

    $optimizer_html = '<div class="fixed-optimize-container">';
    $optimizer_html .= '<h3>Оптимизация изображения</h3>';
    $optimizer_html .= '<div>';
    $optimizer_html .= '<select class="fixed-format-select" id="fixed-format-select-' . esc_attr($post->ID) . '">';
    $optimizer_html .= '<option value="webp">WebP формат</option>';
    $optimizer_html .= '<option value="original">Оригинальный формат</option>';
    $optimizer_html .= '</select>';
    $optimizer_html .= '</div>';
    $optimizer_html .= '<div><label>Качество: <span id="fixed-quality-value-' . esc_attr($post->ID) . '">80%</span></label>';
    $optimizer_html .= '<input type="range" class="fixed-quality-slider" id="fixed-quality-slider-' . esc_attr($post->ID) . '" data-id="' . esc_attr($post->ID) . '" min="1" max="100" value="80" step="1"></div>';
    $optimizer_html .= '<div><button type="button" class="button fixed-optimize-button" data-id="' . esc_attr($post->ID) . '">Оптимизировать</button>';
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

            $this.append(
              '<div class="fixed-optimize-container">' +
              '<h3>Оптимизация изображения</h3>' +
              '<div>' +
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
              '<button type="button" class="button fixed-optimize-button" data-id="' + id + '">Оптимизировать</button>' +
              '<div id="fixed-optimize-result-' + id + '" class="fixed-optimize-result"></div>' +
              '</div>' +
              '</div>'
            );
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

    // Получаем размеры изображения
    $image_size = @getimagesize($image_path);
    if (!$image_size) {
      wp_send_json_error('Не удалось получить размеры изображения');
    }

    list($width, $height) = $image_size;

    // Определяем новые размеры с соблюдением пропорций
    $new_dimensions = $this->calculate_dimensions($width, $height, 1920, 1080);
    $new_width = $new_dimensions['width'];
    $new_height = $new_dimensions['height'];

    // Информация о исходном файле
    $file_info = pathinfo($image_path);
    $uploads_dir = wp_upload_dir();

    // Определяем расширение нового файла в зависимости от выбранного формата
    $output_extension = ($format === 'webp') ? 'webp' : $file_info['extension'];
    $new_filename = $file_info['filename'] . '-optimized.' . $output_extension;
    $new_file_path = $file_info['dirname'] . '/' . $new_filename;

    // Создаем оптимизированное изображение
    if ($format === 'webp') {
      $result = $this->create_webp_image($image_path, $new_file_path, $new_width, $new_height, $quality);
    } else {
      $result = $this->create_optimized_image($image_path, $new_file_path, $new_width, $new_height, $quality);
    }

    if ($result) {
      // Добавляем новое изображение в медиатеку
      $attachment = $this->create_attachment($new_file_path, $attachment_id, $format);

      if ($attachment) {
        // Формируем текст для вывода
        $original_size = filesize($image_path);
        $optimized_size = filesize($new_file_path);
        $saved_percent = round(100 - ($optimized_size / $original_size * 100));

        $image_url = wp_get_attachment_url($attachment);

        $message = sprintf(
          'Успешно оптимизировано! Новый размер: %dx%d. Сохранено: %d%%. <a href="%s" target="_blank">Просмотреть</a>',
          $new_width,
          $new_height,
          $saved_percent,
          esc_url($image_url)
        );

        wp_send_json_success(array(
          'message' => $message,
          'attachment_id' => $attachment,
          'image_url' => $image_url
        ));
      } else {
        wp_send_json_error('Ошибка при создании вложения в медиатеке');
      }
    } else {
      wp_send_json_error('Ошибка при создании оптимизированного изображения');
    }
  }

  // Расчет новых размеров с сохранением пропорций
  private function calculate_dimensions($width, $height, $max_width, $max_height)
  {
    // Если изображение уже в пределах допустимых размеров
    if ($width <= $max_width && $height <= $max_height) {
      return array('width' => $width, 'height' => $height);
    }

    $ratio = $width / $height;

    if ($width > $max_width) {
      $width = $max_width;
      $height = $width / $ratio;
    }

    if ($height > $max_height) {
      $height = $max_height;
      $width = $height * $ratio;
    }

    return array('width' => round($width), 'height' => round($height));
  }

  // Создание изображения WebP
  private function create_webp_image($source_path, $dest_path, $new_width, $new_height, $quality)
  {
    $image_type = exif_imagetype($source_path);
    $image = null;

    // Создаем ресурс изображения в зависимости от формата
    switch ($image_type) {
      case IMAGETYPE_JPEG:
        $image = @imagecreatefromjpeg($source_path);
        break;
      case IMAGETYPE_PNG:
        $image = @imagecreatefrompng($source_path);
        if ($image) {
          // Для PNG сохраняем прозрачность
          imagepalettetotruecolor($image);
          imagealphablending($image, true);
          imagesavealpha($image, true);
        }
        break;
      case IMAGETYPE_WEBP:
        $image = @imagecreatefromwebp($source_path);
        break;
      default:
        return false;
    }

    if (!$image) {
      return false;
    }

    // Создаем новое изображение с требуемыми размерами
    $new_image = imagecreatetruecolor($new_width, $new_height);

    if (!$new_image) {
      imagedestroy($image);
      return false;
    }

    // Сохраняем прозрачность для PNG
    if ($image_type === IMAGETYPE_PNG) {
      imagepalettetotruecolor($new_image);
      imagealphablending($new_image, false);
      imagesavealpha($new_image, true);
    }

    // Изменяем размер изображения
    imagecopyresampled(
      $new_image,
      $image,
      0,
      0,
      0,
      0,
      $new_width,
      $new_height,
      imagesx($image),
      imagesy($image)
    );

    // Сохраняем в формате WebP
    $result = imagewebp($new_image, $dest_path, $quality);

    // Освобождаем память
    imagedestroy($image);
    imagedestroy($new_image);

    return $result;
  }

  // Создание оптимизированного изображения в оригинальном формате
  private function create_optimized_image($source_path, $dest_path, $new_width, $new_height, $quality)
  {
    $image_type = exif_imagetype($source_path);
    $image = null;

    // Создаем ресурс изображения в зависимости от формата
    switch ($image_type) {
      case IMAGETYPE_JPEG:
        $image = @imagecreatefromjpeg($source_path);
        break;
      case IMAGETYPE_PNG:
        $image = @imagecreatefrompng($source_path);
        if ($image) {
          // Для PNG сохраняем прозрачность
          imagepalettetotruecolor($image);
          imagealphablending($image, true);
          imagesavealpha($image, true);
        }
        break;
      case IMAGETYPE_WEBP:
        $image = @imagecreatefromwebp($source_path);
        break;
      default:
        return false;
    }

    if (!$image) {
      return false;
    }

    // Создаем новое изображение с требуемыми размерами
    $new_image = imagecreatetruecolor($new_width, $new_height);

    if (!$new_image) {
      imagedestroy($image);
      return false;
    }

    // Сохраняем прозрачность для PNG
    if ($image_type === IMAGETYPE_PNG) {
      imagepalettetotruecolor($new_image);
      imagealphablending($new_image, false);
      imagesavealpha($new_image, true);
    }

    // Изменяем размер изображения
    imagecopyresampled(
      $new_image,
      $image,
      0,
      0,
      0,
      0,
      $new_width,
      $new_height,
      imagesx($image),
      imagesy($image)
    );

    // Сохраняем в соответствующем формате
    $result = false;
    switch ($image_type) {
      case IMAGETYPE_JPEG:
        $result = imagejpeg($new_image, $dest_path, $quality);
        break;
      case IMAGETYPE_PNG:
        // Преобразуем качество для PNG (0-9)
        $png_quality = 9 - round(($quality / 100) * 9);
        $result = imagepng($new_image, $dest_path, $png_quality);
        break;
      case IMAGETYPE_WEBP:
        $result = imagewebp($new_image, $dest_path, $quality);
        break;
    }

    // Освобождаем память
    imagedestroy($image);
    imagedestroy($new_image);

    return $result;
  }

  // Добавление изображения в медиатеку
  private function create_attachment($file_path, $parent_id, $format)
  {
    $file_name = basename($file_path);
    $attachment_title = sanitize_file_name(pathinfo($file_name, PATHINFO_FILENAME));

    // Получаем путь относительно директории uploads
    $uploads_dir = wp_upload_dir();
    $rel_path = str_replace($uploads_dir['basedir'] . '/', '', $file_path);

    // Определяем MIME-тип для нового файла
    $mime_type = 'image/webp';
    if ($format !== 'webp') {
      $file_type = wp_check_filetype($file_path);
      $mime_type = $file_type['type'];
    }

    $attachment = array(
      'guid' => $uploads_dir['baseurl'] . '/' . $rel_path,
      'post_mime_type' => $mime_type,
      'post_title' => $attachment_title,
      'post_content' => '',
      'post_status' => 'inherit'
    );

    // Вставляем запись вложения в базу данных
    $attachment_id = wp_insert_attachment($attachment, $file_path);

    if (!$attachment_id) {
      return false;
    }

    // Генерируем метаданные для изображения
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
    wp_update_attachment_metadata($attachment_id, $attachment_data);

    return $attachment_id;
  }
}

// Инициализация плагина
$fixed_image_optimizer = new Fixed_Image_Optimizer();