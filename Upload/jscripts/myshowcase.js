var Showcase = {
    init: function () {
        return true;
    },

    removeShowcase: function (entry_id) {
        if (confirm(removeshowcase_confirm) == true) {
            document.admin.showcasegid.value = entry_id;
            document.admin.showcaseact.value = "remove";
        } else {
            document.admin.showcasegid.value = 0;
            document.admin.showcaseact.value = "";
            return false;
        }
    },

    editShowcase: function (entry_id) {
        document.admin.showcasegid.value = entry_id;
        document.admin.showcaseact.value = "edit";
    },

    confirmSubmit: function (confirmationText, formID) {
        if (confirm(confirmationText)) {
            document.getElementById(formID).submit();

            return true;
        } else {
            return false;
        }
    },

    reportEntry: function (entry_slug, showcaseID) {
        MyBB.popupWindow("/report.php?modal=1&type=showcase_entries&pid=" + entry_slug + "&showcaseID=" + showcaseID);
    },

    reportComment: function (comment_slug, showcaseID) {
        MyBB.popupWindow("/report.php?modal=1&type=showcase_comments&pid=" + comment_slug + "&showcaseID=" + showcaseID);
    },

    showDeletedEntry: function (entry_slug) {
        $('#deleted_entry_' + entry_slug).slideToggle("slow");
        $('#entryBody' + entry_slug).slideToggle("slow");
    },

    showDeletedComment: function (comment_slug) {
        $('#deleted_comment_' + comment_slug).slideToggle("slow");
        $('#commentBody' + comment_slug).slideToggle("slow");
    },

    showIgnoredEntry: function (entry_slug) {
        $('#ignored_entry_' + entry_slug).slideToggle("slow");
        $('#entryBody' + entry_slug).slideToggle("slow");
    },

    showIgnoredComment: function (comment_slug) {
        $('#ignored_comment_' + comment_slug).slideToggle("slow");
        $('#commentBody' + comment_slug).slideToggle("slow");
    },
};
Event.observe(document, 'dom:loaded', Showcase.init);
