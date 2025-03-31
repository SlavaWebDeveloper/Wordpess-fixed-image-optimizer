jQuery(document).ready(function($) {
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
                        var $thumbnail = $(".attachment[data-id='" + response.data.attachment_id + "'] .thumbnail");
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
                                var $newAttachment = $(".attachment[data-id='" + newAttachmentId + "']");
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
});