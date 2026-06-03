jQuery(document).ready(function($) {
    const pluginTable = $('.wp-list-table.plugins tbody');
    let categories = {};

    // 1. Inject "Add Category" UI matching the screenshot
    $('.subsubsub').wrap('<div class="spo-top-wrapper" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;"></div>');
    $('.spo-top-wrapper').append(`
        <div class="spo-add-category-inline" style="background: #fff; border: 1px solid #c3c4c7; padding: 5px 10px; display: flex; align-items: center;">
            <strong style="margin-right: 10px; font-weight: 500;">Add Category:</strong> 
            <input type="text" id="spo-new-cat-name" placeholder="Name" style="margin: 0 10px 0 0; padding: 0 8px; min-height: 30px;" />
            <button id="spo-add-cat-btn" class="button button-secondary" style="color: #2271b1; border-color: #2271b1;">Add</button>
            <span id="spo-action-spinner" class="spinner" style="float: none; margin: 0 5px;"></span>
        </div>
    `);

    // 2. Map all plugins to their categories
    pluginTable.find('tr').each(function() {
        if ($(this).hasClass('no-items') || $(this).hasClass('plugin-update-tr')) return;

        let catLabel = $(this).find('.plugin-cat-label');
        if (catLabel.length) {
            let catName = catLabel.data('category');
            let catId = catLabel.data('cat-id');
            if (!categories[catName]) categories[catName] = { id: catId, rows: [] };
            
            let updateRow = $(this).next('.plugin-update-tr');
            categories[catName].rows.push({ mainRow: $(this), updateRow: updateRow });
        }
    });

    // 3. Clear the table and reconstruct based on Saved Order
    pluginTable.empty();
    
    // We use the ordered list from PHP to render headers in the correct order
    let orderedIds = pluginCatApp.ordered_cats;
    
    // Render ordered categories first
    $.each(orderedIds, function(catId, catName) {
        if(categories[catName]) renderCategoryGroup(catName, categories[catName]);
    });

    // Render "Uncategorized" at the absolute bottom
    if (categories['Uncategorized']) {
        renderCategoryGroup('Uncategorized', categories['Uncategorized']);
    }

    function renderCategoryGroup(catName, catData) {
        let isUncat = (catName === 'Uncategorized');
        let headerClass = isUncat ? 'uncategorized-header' : '';
        let dragIcon = isUncat ? '' : '<span class="dashicons dashicons-menu spo-drag-handle" style="cursor: grab; color: #a7aaad; margin-right: 8px;"></span>';
        
        let actionIcons = '';
        if (!isUncat) {
            actionIcons = `
                <span class="dashicons dashicons-edit edit-category" data-id="${catData.id}" data-name="${catName}" title="Edit Name" style="cursor: pointer; color: #2271b1; font-size: 16px; width: 16px; height: 16px; margin-left:10px;"></span>
                <span class="dashicons dashicons-trash delete-category" data-id="${catData.id}" title="Delete Category" style="cursor: pointer; color: #d63638; font-size: 16px; width: 16px; height: 16px; margin-left:5px;"></span>
            `;
        }

        let headerRow = $(`
            <tr class="plugin-category-header ${headerClass}" style="background: #fff; border-bottom: 1px solid #c3c4c7;">
                <td colspan="4" style="padding: 12px 10px;">
                    ${dragIcon}
                    <span class="dashicons dashicons-arrow-down toggle-category" style="cursor: pointer; margin-right: 5px;"></span>
                    <strong class="cat-title-text">${catName}</strong> <span class="count" style="color: #646970;">(${catData.rows.length})</span>
                    ${actionIcons}
                </td>
            </tr>
        `);

        pluginTable.append(headerRow);

        $.each(catData.rows, function(index, rowData) {
            rowData.mainRow.attr('data-cat-group', catName);
            pluginTable.append(rowData.mainRow);
            if (rowData.updateRow.length) {
                rowData.updateRow.attr('data-cat-group', catName);
                pluginTable.append(rowData.updateRow);
            }
        });
    }

    // 4. Initialize Drag and Drop Sorting
    pluginTable.sortable({
        items: '.plugin-category-header:not(.uncategorized-header)',
        handle: '.spo-drag-handle',
        axis: 'y',
        helper: function(e, tr) {
            let originals = tr.children();
            let helper = tr.clone();
            helper.children().each(function(index) { $(this).width(originals.eq(index).width()); });
            helper.css('background', '#f6f7f7'); // Highlight while dragging
            return helper;
        },
        update: function(event, ui) {
            let order = [];
            
            // Loop through all headers in their new DOM order
            pluginTable.find('.plugin-category-header').each(function() {
                let catName = $(this).find('.cat-title-text').text();
                let catId = $(this).find('.edit-category').data('id');
                
                if (catId) order.push(catId);
                
                // Immediately snap the plugin rows underneath their newly positioned header
                let rows = $(`tr[data-cat-group="${catName}"]`);
                $(this).after(rows);
            });

            // Ensure Uncategorized and its rows stay firmly at the bottom
            let uncatHeader = pluginTable.find('.uncategorized-header');
            if(uncatHeader.length) {
                pluginTable.append(uncatHeader);
                pluginTable.append($(`tr[data-cat-group="Uncategorized"]`));
            }

            // Save order to database
            $.post(pluginCatApp.ajax_url, {
                action: 'spo_reorder_categories',
                nonce: pluginCatApp.nonce,
                order: order
            });
        }
    });

    // --- EVENTS & AJAX ---

    // Toggle Category Rows
    $(document).on('click', '.toggle-category', function() {
        let catName = $(this).siblings('.cat-title-text').text();
        $(`tr[data-cat-group="${catName}"]`).toggle();
        $(this).toggleClass('dashicons-arrow-down dashicons-arrow-right');
    });

    // Inline Plugin Assignment
    $(document).on('change', '.spo-inline-assign', function() {
        let pluginFile = $(this).data('plugin');
        let catId = $(this).val();
        $(this).css('opacity', '0.5');

        $.post(pluginCatApp.ajax_url, {
            action: 'spo_assign_plugin',
            nonce: pluginCatApp.nonce,
            plugin_file: pluginFile,
            cat_id: catId
        }, function(response) {
            if (response.success) location.reload();
        });
    });

    // Add New Category
    $('#spo-add-cat-btn').on('click', function(e) {
        e.preventDefault();
        let catName = $('#spo-new-cat-name').val().trim();
        if (!catName) return;

        $('#spo-action-spinner').addClass('is-active');

        $.post(pluginCatApp.ajax_url, {
            action: 'spo_add_category',
            nonce: pluginCatApp.nonce,
            cat_name: catName
        }, function(response) {
            if (response.success) location.reload();
        });
    });

    // Edit Category Name
    $(document).on('click', '.edit-category', function() {
        let catId = $(this).data('id');
        let currentName = $(this).data('name');
        
        let newName = prompt("Edit category name:", currentName);
        if (newName !== null && newName.trim() !== "" && newName !== currentName) {
            $.post(pluginCatApp.ajax_url, {
                action: 'spo_edit_category',
                nonce: pluginCatApp.nonce,
                cat_id: catId,
                cat_name: newName.trim()
            }, function(response) {
                if (response.success) location.reload();
            });
        }
    });

    // Delete Category
    $(document).on('click', '.delete-category', function() {
        if (!confirm("Are you sure you want to delete this category? (Plugins will just be moved to Uncategorized).")) return;
        let catId = $(this).data('id');

        $.post(pluginCatApp.ajax_url, {
            action: 'spo_delete_category',
            nonce: pluginCatApp.nonce,
            cat_id: catId
        }, function(response) {
            if (response.success) location.reload();
        });
    });
});