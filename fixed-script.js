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
                        var $thumbnail = $(".attachment[data-id='" + response.data.original_id + "'] .thumbnail");
                        if ($thumbnail.length) {
                            // Добавляем timestamp для избежания кеширования
                            var randomParam = "?t=" + new Date().getTime();
                            
                            // Если у нас есть бейдж оптимизации, обновляем его, иначе добавляем
                            if (!$thumbnail.find(".fixed-optimization-badge").length) {
                                $thumbnail.append("<div class='fixed-optimization-badge' title='Оптимизировано'>✓</div>");
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
                                var $attachment = $(".attachment[data-id='" + attachmentId + "']");
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
            
            var container = $("<div class='fixed-optimize-container'>" +
                "<h3>Оптимизация изображения</h3>");
            
            // Если есть информация об оптимизации, добавляем ее
            if (hasOptimized && optimizationInfo) {
                container.append("<div id='fixed-optimization-info-" + id + "' class='fixed-optimization-info'>" + optimizationInfo + "</div>");
            }
            
            container.append("<div>" +
                "<select class='fixed-format-select' id='fixed-format-select-" + id + "'>" +
                "<option value='webp'>WebP формат</option>" +
                "<option value='original'>Оригинальный формат</option>" +
                "</select>" +
                "</div>" +
                "<div>" +
                "<label>Качество: <span id='fixed-quality-value-" + id + "'>80%</span></label>" +
                "<input type='range' class='fixed-quality-slider' id='fixed-quality-slider-" + id + "' data-id='" + id + "' min='1' max='100' value='80' step='1'>" +
                "</div>" +
                "<div>" +
                "<button type='button' class='button fixed-optimize-button' data-id='" + id + "' data-has-optimized='" + (hasOptimized ? "yes" : "no") + "'>" + 
                (hasOptimized ? "Переоптимизировать" : "Оптимизировать") + "</button>" +
                "<div id='fixed-optimize-result-" + id + "' class='fixed-optimize-result'></div>" +
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
});