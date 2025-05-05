<!-- Add this right after the <head> section in your HTML -->
<style>
    /* Override the mobile-swiper-container display style */
    .mobile-swiper-container {
        display: block !important;
        width: 100% !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
    
    /* Make dropdowns appear on top of everything */
    .dropdown-menu.show {
        position: fixed !important;
        z-index: 100000 !important;
        display: block !important;
        transform: none !important;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.2) !important;
    }
    
    /* Ensure actions column is visible */
    .actions-column {
        position: relative !important;
        z-index: 500 !important;
    }
    
    /* Fix the card footer positioning */
    .card-footer {
        position: relative !important;
        z-index: 10 !important;
        background-color: #fff !important;
    }
    
    /* Fix table layout issues */
    .table-responsive {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: thin;
    }
    
    /* Ensure the table takes full width of container */
    .table {
        width: 100% !important;
        min-width: 800px !important; /* Ensure there's always horizontal scroll on mobile */
    }
    
    /* Make sure all cells have proper padding */
    .table td, .table th {
        padding: 0.75rem !important;
        vertical-align: middle !important;
    }
    
    /* Fix for swiper indicator */
    .swipe-indicator {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 8px !important;
        background-color: rgba(0,0,0,0.05) !important;
        margin-bottom: 10px !important;
        border-radius: 4px !important;
    }
</style>

<!-- Replace your JavaScript section at the end of the file with this -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Optional: Swiper JS for mobile carousels -->
<script src="https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.js"></script>
<script>
    // Universal dropdown fix - this is critical
    function repositionAllDropdowns() {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
            const button = menu.previousElementSibling;
            if (!button) return;
            
            const rect = button.getBoundingClientRect();
            const viewportHeight = window.innerHeight;
            
            // Check if menu would go below screen bottom
            const willExtendBeyondBottom = (rect.bottom + menu.offsetHeight) > viewportHeight;
            
            // Position menu based on available space
            if (willExtendBeyondBottom && rect.top > menu.offsetHeight) {
                // Position above button
                menu.style.position = 'fixed';
                menu.style.top = (rect.top - menu.offsetHeight) + 'px';
                menu.style.left = rect.left + 'px';
            } else {
                // Position below button
                menu.style.position = 'fixed';
                menu.style.top = rect.bottom + 'px';
                menu.style.left = rect.left + 'px';
            }
            
            // Make sure it's on top of everything
            menu.style.zIndex = '100000';
        });
    }
    
    // Apply fixes when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Handle table swipe functionality
        const tableContainer = document.querySelector('.mobile-swiper-container');
        if (tableContainer) {
            let isDown = false;
            let startX;
            let scrollLeft;
            
            // Mouse events for desktop
            tableContainer.addEventListener('mousedown', (e) => {
                isDown = true;
                tableContainer.style.cursor = 'grabbing';
                startX = e.pageX - tableContainer.offsetLeft;
                scrollLeft = tableContainer.scrollLeft;
            });
            
            tableContainer.addEventListener('mouseleave', () => {
                isDown = false;
                tableContainer.style.cursor = 'grab';
            });
            
            tableContainer.addEventListener('mouseup', () => {
                isDown = false;
                tableContainer.style.cursor = 'grab';
            });
            
            tableContainer.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault();
                const x = e.pageX - tableContainer.offsetLeft;
                const walk = (x - startX) * 2; // Scroll speed
                tableContainer.scrollLeft = scrollLeft - walk;
            });
            
            // Touch events for mobile
            tableContainer.addEventListener('touchstart', (e) => {
                isDown = true;
                startX = e.touches[0].pageX - tableContainer.offsetLeft;
                scrollLeft = tableContainer.scrollLeft;
            }, {passive: true});
            
            tableContainer.addEventListener('touchend', () => {
                isDown = false;
            }, {passive: true});
            
            tableContainer.addEventListener('touchmove', (e) => {
                if (!isDown) return;
                const x = e.touches[0].pageX - tableContainer.offsetLeft;
                const walk = (x - startX) * 2;
                tableContainer.scrollLeft = scrollLeft - walk;
            }, {passive: true});
        }
        
        // Fix filter swiper
        const mobileFiltersSwiper = document.querySelector('.mobile-filters-swiper');
        if (mobileFiltersSwiper) {
            let isDown = false;
            let startX;
            let scrollLeft;
            
            // Touch events
            mobileFiltersSwiper.addEventListener('touchstart', (e) => {
                isDown = true;
                startX = e.touches[0].pageX - mobileFiltersSwiper.offsetLeft;
                scrollLeft = mobileFiltersSwiper.scrollLeft;
            }, {passive: true});
            
            mobileFiltersSwiper.addEventListener('touchend', () => {
                isDown = false;
            }, {passive: true});
            
            mobileFiltersSwiper.addEventListener('touchmove', (e) => {
                if (!isDown) return;
                const x = e.touches[0].pageX - mobileFiltersSwiper.offsetLeft;
                const walk = (x - startX) * 2;
                mobileFiltersSwiper.scrollLeft = scrollLeft - walk;
            }, {passive: true});
        }
        
        // Enhanced dropdown positioning
        // Intercept Bootstrap's dropdown events
        document.body.addEventListener('shown.bs.dropdown', function(e) {
            repositionAllDropdowns();
        });
        
        // Reposition on window scroll
        window.addEventListener('scroll', function() {
            repositionAllDropdowns();
        }, {passive: true});
        
        // Reposition on window resize
        window.addEventListener('resize', function() {
            repositionAllDropdowns();
        }, {passive: true});
        
        // Make all action dropdowns work correctly
        document.querySelectorAll('.action-dropdown-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                // Wait for Bootstrap to show the dropdown
                setTimeout(() => {
                    const dropdown = this.nextElementSibling;
                    if (!dropdown.classList.contains('show')) return;
                    
                    // Get button position
                    const rect = this.getBoundingClientRect();
                    const viewportHeight = window.innerHeight;
                    const viewportWidth = window.innerWidth;
                    
                    // Calculate if dropdown would go beyond viewport
                    const dropdownHeight = dropdown.offsetHeight || 200; // Approximate if not visible
                    
                    // Check if dropdown would go below viewport
                    if (rect.bottom + dropdownHeight > viewportHeight) {
                        // Position above button
                        dropdown.style.position = 'fixed';
                        dropdown.style.top = (rect.top - dropdownHeight) + 'px';
                        dropdown.style.left = Math.max(0, rect.left) + 'px';
                    } else {
                        // Position below button
                        dropdown.style.position = 'fixed';
                        dropdown.style.top = rect.bottom + 'px';
                        dropdown.style.left = Math.max(0, rect.left) + 'px';
                    }
                    
                    // Ensure dropdown doesn't go off screen horizontally
                    const dropdownRect = dropdown.getBoundingClientRect();
                    if (dropdownRect.right > viewportWidth) {
                        dropdown.style.left = (viewportWidth - dropdown.offsetWidth - 5) + 'px';
                    }
                    
                    // Make sure it's on top of everything
                    dropdown.style.zIndex = '100000';
                }, 10);
            });
        });
    });
    
    // Legacy code - keep this for backward compatibility
    // Toggle Sidebar
    document.getElementById('sidebarToggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('collapsed');
        document.querySelector('.main-content').classList.toggle('expanded');
    });
    
    // Select/Deselect all checkbox functionality
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.article-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
    
    // Update "select all" checkbox when individual checkboxes change
    document.querySelectorAll('.article-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectAllCheckbox);
    });
    
    function updateSelectAllCheckbox() {
        const checkboxes = document.querySelectorAll('.article-checkbox');
        const checkedBoxes = document.querySelectorAll('.article-checkbox:checked');
        const selectAllCheckbox = document.getElementById('selectAll');
        
        if (checkboxes.length === checkedBoxes.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedBoxes.length > 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }
    }
    
    // Setup delete confirmation modal
    let articleIdToDelete = null;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    
    function confirmDelete(articleId) {
        articleIdToDelete = articleId;
        deleteModal.show();
    }
    
    document.getElementById('confirmDelete').addEventListener('click', function() {
        if (articleIdToDelete) {
            document.getElementById('action').value = 'delete';
            document.getElementById('article_id').value = articleIdToDelete;
            document.getElementById('articlesForm').submit();
        }
        deleteModal.hide();
    });
    
    // Single article actions
    function singleAction(articleId, action) {
        document.getElementById('action').value = action;
        document.getElementById('article_id').value = articleId;
        document.getElementById('articlesForm').submit();
    }
    
    // Apply bulk actions
    document.getElementById('applyBulkAction').addEventListener('click', function() {
        const selectedAction = document.getElementById('bulk_action').value;
        const checkedBoxes = document.querySelectorAll('.article-checkbox:checked');
        
        if (selectedAction === '') {
            alert('Please select an action to apply');
            return;
        }
        
        if (checkedBoxes.length === 0) {
            alert('Please select at least one article');
            return;
        }
        
        if (selectedAction === 'delete') {
            if (!confirm('Are you sure you want to delete the selected articles? This action cannot be undone.')) {
                return;
            }
        }
        
        // Set the action field and submit the form
        document.getElementById('bulk_action').setAttribute('name', 'bulk_action');
        document.getElementById('articlesForm').submit();
    });
</script>