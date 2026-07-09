peque.news = function ()
{
    'use strict';

    var initArticle = function ()
    {
        $("textarea[name=text]").sceditor({
            plugins: "bbcode",
            readOnly: true,
            toolbar: '',
            emoticonsEnabled: false,
            width: '90%',
            style: "/css/lib/jquery.sceditor.default.min.css"
        });

        $('.vote-container button').on("click", function () {
            var id = parseInt($(this).data("id"));

            if (id < 1) {
                return false;
            }

            peque.api("article/vote", {id: id}, function (data) {
                if (data.error > 0) {
                    return false;
                }

                var $count = $('.vote-container h1');

                $count.text(parseInt($count.text() + 1));
            });
        });
    };

    var initArticleForm = function ()
    {
        $("textarea[name=text]").sceditor({
            plugins: "bbcode",
            toolbar: 'bold,italic,underline,strike|left,center,right,justify|size,color,removeformat,pastetext|bulletlist,orderedlist|quote,image,youtube,link,unlink|maximize,source',
            emoticonsEnabled: false,
            width: '90%',
            style: "/css/lib/jquery.sceditor.default.min.css"
        });

        $('#article-creation-form').on("submit", function (e) {
            e.preventDefault();

            var title = $('[name=title]').val(),
                category = $('[name=category]').val(),
                text = $('[name=text]').val();

            if (title.length < 3 || text.length < 3 ) {
                return false;
            }

            peque.api("article/create", {title: title, text: text, category: category}, function (data) {
                var payload = (data && typeof data.result === "object") ? data.result : data;
                var hasError = payload && (payload.error === true || payload.error > 0);
                var articleId = 0;

                if (payload && payload.id) {
                    articleId = parseInt(payload.id, 10) || 0;
                } else if (data && typeof data.result === "number") {
                    articleId = parseInt(data.result, 10) || 0;
                }

                if (hasError || articleId < 1) {
                    return false;
                }

                peque.navigation.redirect("/news/article/" + articleId);
            });
        });
    };

    var initCreationForm = function ()
    {
        $('#newspaper-creation-form').on("submit", function (e) {
            e.preventDefault();

            var name = $('[name=name]').val(),
                description = $('[name=description]').val();

            if (name.length < 3 || description.length < 3 ) {
                return false;
            }

            peque.api("newspaper/create", {name: name, description: description}, function (data) {
                var payload = (data && typeof data.result === "object") ? data.result : data;
                var hasError = payload && (payload.error === true || payload.error > 0);
                var newspaperId = 0;

                if (payload && payload.id) {
                    newspaperId = parseInt(payload.id, 10) || 0;
                } else if (data && typeof data.result === "number") {
                    newspaperId = parseInt(data.result, 10) || 0;
                }

                if (hasError || newspaperId < 1) {
                    return false;
                }

                peque.navigation.redirect("/newspaper/" + newspaperId);
            });
        });
    };

    return {
        initArticle: initArticle,
        initCreationForm: initCreationForm,
        initArticleForm: initArticleForm
    };
}();
