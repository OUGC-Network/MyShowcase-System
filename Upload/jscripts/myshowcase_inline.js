const inlineModeration = {
    init: function () {
        let tr;
        inlineModeration.inlineCount = 0;
        if (!inlineType || !inlineId) {
            return false;
        }

        inlineModeration.cookieName = "inlinemod_" + inlineType + inlineId;
        let inputs = document.getElementsByTagName("input");

        if (!inputs) {
            return false;
        }

        let inlineCookie = Cookie.get(inlineModeration.cookieName);

        let inlineIds;
        if (inlineCookie) {
            inlineIds = inlineCookie.split("|");
        }

        let inlineCheck;
        let id;
        for (let i = 0; i < inputs.length; i++) {
            const element = inputs[i];
            if ((element.name !== "allbox") && (element.type === "checkbox") && (element.id.split("_")[0] === "inlinemod")) {
                Event.observe(element, "click", inlineModeration.checkItem);
            }

            if (inlineCookie) {
                inlineCheck = element.id.split("_");
                id = inlineCheck[1];

                if (inlineIds.indexOf(id) !== -1) {
                    element.checked = true;
                    tr = element.up('tr');

                    if (tr) {
                        tr.addClassName('trow_selected');
                    }
                } else {
                    element.checked = false;
                    tr = element.up('tr');
                    if (tr) {
                        tr.removeClassName('trow_selected');
                    }
                }
            }
        }

        let goButton;
        if (inlineCookie) {
            goButton = $("inline_go");
            if (inlineIds) {
                let inlineCount = 0;
                inlineIds.each(function (item) {
                    if (item !== '') inlineCount++;
                });
                inlineModeration.inlineCount = inlineCount;
            }
            goButton.value = go_text + " (" + (inlineModeration.inlineCount) + ")";
        }
        return true;
    },

    checkItem: function (e) {
        let tr;
        let element = Event.element(e);

        if (!element) {
            return false;
        }

        let inlineCheck = element.id.split("_");
        let id = inlineCheck[1];

        if (!id) {
            return false;
        }

        const newIds = [];
        let inlineCookie = Cookie.get(inlineModeration.cookieName);

        let inlineIds;
        if (inlineCookie) {
            inlineIds = inlineCookie.split("|");
            inlineIds.each(function (item) {
                if (item !== "" && item != null) {
                    if (item !== id) {
                        newIds[newIds.length] = item;
                    }
                }
            });
        }

        if (element.checked === true) {
            inlineModeration.inlineCount++;
            newIds[newIds.length] = id;
            tr = element.up('tr');
            if (tr) {
                tr.addClassName('trow_selected');
            }
        } else {
            inlineModeration.inlineCount--;
            tr = element.up('tr');
            if (tr) {
                tr.removeClassName('trow_selected');
            }
        }

        let inlineData = "|" + newIds.join("|") + "|";
        let goButton = $("inline_go");

        if (inlineModeration.inlineCount < 0) {
            inlineModeration.inlineCount = 0;
        }

        goButton.value = go_text + " (" + inlineModeration.inlineCount + ")";
        Cookie.set(inlineModeration.cookieName, inlineData, 3600000);

        return true;
    },

    clearChecked: function () {
        $('#selectAllrow').hide();
        $('#allSelectedrow').hide();

        const inputs = $('input');

        if (!inputs.length) {
            return false;
        }

        $(inputs).each(function () {
            const element = $(this);
            if (!element.val()) return;
            if (element.attr('type') == 'checkbox' && ((element.attr('id') && element.attr('id').split('_')[0] == 'inlinemod') || element.attr('name') == 'allbox')) {
                element.prop('checked', false);
            }
        });

        $('.trow_selected').each(function () {
            $(this).removeClass('trow_selected');
        });

        $('fieldset.inline_selected').each(function () {
            $(this).removeClass('inline_selected');
        });

        $('#inline_go').html(go_text + ' (0)');
        Cookie.unset(inlineModeration.cookieName);
        Cookie.unset(inlineModeration.cookieName + '_removed');

        return true;
    },

    checkAll: function (master) {
        inputs = document.getElementsByTagName("input");

        if (!inputs) {
            return false;
        }

        inlineCookie = Cookie.get(inlineModeration.cookieName);

        if (inlineCookie) {
            inlineIds = inlineCookie.split("|");
        }

        var newIds = new Array();
        $H(inputs).each(function (element) {
            var element = element.value;
            if (!element.value) return;
            inlineCheck = element.id.split("_");
            if ((element.name != "allbox") && (element.type == "checkbox") && (inlineCheck[0] == "inlinemod")) {
                id = inlineCheck[1];
                var changed = (element.checked != master.checked);
                element.checked = master.checked;

                var tr = element.up('tr');
                if (tr && master.checked == true) {
                    tr.addClassName('trow_selected');
                } else {
                    tr.removeClassName('trow_selected');
                }

                if (changed) {
                    if (master.checked == true) {
                        inlineModeration.inlineCount++;
                        newIds[newIds.length] = id;
                    } else {
                        inlineModeration.inlineCount--;
                    }
                }
            }
        });

        inlineData = "|" + newIds.join("|") + "|";
        goButton = $("inline_go");

        if (inlineModeration.inlineCount < 0) {
            inlineModeration.inlineCount = 0;
        }

        goButton.value = go_text + " (" + inlineModeration.inlineCount + ")";
        Cookie.set(inlineModeration.cookieName, inlineData, 3600000);
    }
};
Event.observe(document, "dom:loaded", inlineModeration.init);