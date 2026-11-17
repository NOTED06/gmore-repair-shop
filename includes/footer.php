<?php if (isLoggedIn()): ?>
            </main>
            
        </div>
        
    </div>
    
<?php endif; ?>
    
    
    <meta name="site-url" content="<?= SITE_URL ?>">
    
    
    <script src="<?= SITE_URL ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/notifications.js"></script>

    
    <script>
    (function() {
        document.addEventListener('DOMContentLoaded', function() {
            var input = document.getElementById('globalSearchInput');
            var dropdown = document.getElementById('liveSearchDropdown');
            if (!input || !dropdown) return;

            var siteUrl = document.querySelector('meta[name="site-url"]').getAttribute('content');
            var debounceTimer = null;
            var activeIndex = -1;

            function doSearch() {
                var q = input.value.trim();
                if (q.length < 1) {
                    dropdown.classList.remove('open');
                    dropdown.innerHTML = '';
                    return;
                }

                dropdown.innerHTML = '<div class="ls-loading"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
                dropdown.classList.add('open');
                activeIndex = -1;

                fetch(siteUrl + '/api/live-search.php?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) { renderResults(data, q); })
                    .catch(function() {
                        dropdown.innerHTML = '<div class="ls-empty"><i class="fas fa-exclamation-circle"></i>Search failed</div>';
                    });
            }

            function renderResults(data, q) {
                var html = '';
                var hasProducts = data.products && data.products.length > 0;
                var hasRepairs  = data.repairs && data.repairs.length > 0;
                var hasCustomers = data.customers && data.customers.length > 0;
                var hasTechnicians = data.technicians && data.technicians.length > 0;
                var role = data.role || 'customer';

                if (!hasProducts && !hasRepairs && !hasCustomers && !hasTechnicians) {
                    dropdown.innerHTML = '<div class="ls-empty"><i class="fas fa-search"></i>No results for "' + escHtml(q) + '"</div>';
                    return;
                }

                if (hasProducts) {
                    html += '<div class="ls-section-label"><i class="fas fa-box"></i> Products</div>';
                    data.products.forEach(function(p) {
                        var icon = p.image_url
                            ? '<img class="ls-item-thumb" src="' + escHtml(p.image_url) + '" alt="">'
                            : '<div class="ls-item-icon product"><i class="fas fa-box-open"></i></div>';

                        var productUrl = (role === 'admin')
                            ? siteUrl + '/admin/inventory.php'
                            : siteUrl + '/customer/dashboard.php#product-' + p.product_id;

                        html += '<a class="ls-item" href="' + productUrl + '">'
                            + icon
                            + '<span class="ls-item-name">' + highlight(p.product_name, q) + '</span>'
                            + '<span class="ls-item-right price">' + escHtml(p.price_formatted) + '</span>'
                            + '</a>';
                    });
                }

                if (hasRepairs) {
                    html += '<div class="ls-section-label"><i class="fas fa-wrench"></i> Repairs</div>';
                    data.repairs.forEach(function(r) {
                        var detailUrl = siteUrl + '/' + role + '/repair-detail.php?id=' + r.repair_id;
                        if (role === 'admin') detailUrl = siteUrl + '/admin/repair-view.php?id=' + r.repair_id;

                        var label = r.appliance_type;
                        if (r.brand) label += ' ' + r.brand;
                        if (r.customer_name) label += ' — ' + r.customer_name;

                        var bg = '#6c757d';
                        if (r.repair_status === 'Completed' || r.repair_status === 'Returned') bg = '#28a745';
                        else if (r.repair_status === 'In Progress' || r.repair_status === 'Assigned') bg = '#17a2b8';
                        else if (r.repair_status === 'Pending') bg = '#ffc107';

                        html += '<a class="ls-item" href="' + detailUrl + '">'
                            + '<div class="ls-item-icon repair"><i class="fas fa-tools"></i></div>'
                            + '<span class="ls-item-name">' + highlight(label, q) + '</span>'
                            + '<span class="ls-badge" style="background:' + bg + '">' + escHtml(r.repair_status) + '</span>'
                            + '</a>';
                    });
                }

                if (hasCustomers) {
                    html += '<div class="ls-section-label"><i class="fas fa-users"></i> Customers</div>';
                    data.customers.forEach(function(c) {
                        html += '<a class="ls-item" href="' + siteUrl + '/admin/customer-view.php?id=' + c.customer_id + '">'
                            + '<div class="ls-item-icon customer"><i class="fas fa-user"></i></div>'
                            + '<span class="ls-item-name">' + highlight(c.full_name, q) + '</span>'
                            + '<span class="ls-item-right sub">' + escHtml(c.contact_no || '') + '</span>'
                            + '</a>';
                    });
                }

                if (hasTechnicians) {
                    html += '<div class="ls-section-label"><i class="fas fa-user-cog"></i> Technicians</div>';
                    data.technicians.forEach(function(t) {
                        var bg = t.availability === 'Available' ? '#28a745' : (t.availability === 'Busy' ? '#ffc107' : '#6c757d');
                        html += '<a class="ls-item" href="' + siteUrl + '/admin/technician-view.php?id=' + t.technician_id + '">'
                            + '<div class="ls-item-icon technician"><i class="fas fa-user-cog"></i></div>'
                            + '<span class="ls-item-name">' + highlight(t.full_name, q) + '</span>'
                            + '<span class="ls-badge" style="background:' + bg + '">' + escHtml(t.availability || 'Available') + '</span>'
                            + '</a>';
                    });
                }

                dropdown.innerHTML = html;
            }

            function highlight(text, q) {
                if (!q) return escHtml(text);
                var regex = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\
    (function() {
        document.addEventListener('DOMContentLoaded', function() {
            var input = document.getElementById('globalSearchInput');
            var dropdown = document.getElementById('liveSearchDropdown');
            if (!input || !dropdown) return;

            var siteUrl = document.querySelector('meta[name="site-url"]').getAttribute('content');
            var debounceTimer = null;
            var activeIndex = -1;

            function doSearch() {
                var q = input.value.trim();
                if (q.length < 1) {
                    dropdown.classList.remove('open');
                    dropdown.innerHTML = '';
                    return;
                }

                dropdown.innerHTML = '<div class="ls-loading"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
                dropdown.classList.add('open');
                activeIndex = -1;

                fetch(siteUrl + '/api/live-search.php?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) { renderResults(data, q); })
                    .catch(function() {
                        dropdown.innerHTML = '<div class="ls-empty"><i class="fas fa-exclamation-circle"></i>Search failed</div>';
                    });
            }

            function renderResults(data, q) {
                var html = '';
                var hasProducts = data.products && data.products.length > 0;
                var hasRepairs  = data.repairs && data.repairs.length > 0;
                var hasCustomers = data.customers && data.customers.length > 0;
                var hasTechnicians = data.technicians && data.technicians.length > 0;
                var role = data.role || 'customer';

                if (!hasProducts && !hasRepairs && !hasCustomers && !hasTechnicians) {
                    dropdown.innerHTML = '<div class="ls-empty"><i class="fas fa-search"></i>No results for "' + escHtml(q) + '"</div>';
                    return;
                }

                if (hasProducts) {
                    html += '<div class="ls-section-label"><i class="fas fa-box"></i> Products</div>';
                    data.products.forEach(function(p) {
                        var icon = p.image_url
                            ? '<img class="ls-item-thumb" src="' + escHtml(p.image_url) + '" alt="">'
                            : '<div class="ls-item-icon product"><i class="fas fa-box-open"></i></div>';

                        var productUrl = (role === 'admin')
                            ? siteUrl + '/admin/inventory.php'
                            : siteUrl + '/customer/dashboard.php#product-' + p.product_id;

                        html += '<a class="ls-item" href="' + productUrl + '">'
                            + icon
                            + '<span class="ls-item-name">' + highlight(p.product_name, q) + '</span>'
                            + '<span class="ls-item-right price">' + escHtml(p.price_formatted) + '</span>'
                            + '</a>';
                    });
                }

                if (hasRepairs) {
                    html += '<div class="ls-section-label"><i class="fas fa-wrench"></i> Repairs</div>';
                    data.repairs.forEach(function(r) {
                        var detailUrl = siteUrl + '/' + role + '/repair-detail.php?id=' + r.repair_id;
                        if (role === 'admin') detailUrl = siteUrl + '/admin/repair-view.php?id=' + r.repair_id;

                        var label = r.appliance_type;
                        if (r.brand) label += ' ' + r.brand;
                        if (r.customer_name) label += ' — ' + r.customer_name;

                        var bg = '#6c757d';
                        if (r.repair_status === 'Completed' || r.repair_status === 'Returned') bg = '#28a745';
                        else if (r.repair_status === 'In Progress' || r.repair_status === 'Assigned') bg = '#17a2b8';
                        else if (r.repair_status === 'Pending') bg = '#ffc107';

                        html += '<a class="ls-item" href="' + detailUrl + '">'
                            + '<div class="ls-item-icon repair"><i class="fas fa-tools"></i></div>'
                            + '<span class="ls-item-name">' + highlight(label, q) + '</span>'
                            + '<span class="ls-badge" style="background:' + bg + '">' + escHtml(r.repair_status) + '</span>'
                            + '</a>';
                    });
                }

                if (hasCustomers) {
                    html += '<div class="ls-section-label"><i class="fas fa-users"></i> Customers</div>';
                    data.customers.forEach(function(c) {
                        html += '<a class="ls-item" href="' + siteUrl + '/admin/customer-view.php?id=' + c.customer_id + '">'
                            + '<div class="ls-item-icon customer"><i class="fas fa-user"></i></div>'
                            + '<span class="ls-item-name">' + highlight(c.full_name, q) + '</span>'
                            + '<span class="ls-item-right sub">' + escHtml(c.contact_no || '') + '</span>'
                            + '</a>';
                    });
                }

                if (hasTechnicians) {
                    html += '<div class="ls-section-label"><i class="fas fa-user-cog"></i> Technicians</div>';
                    data.technicians.forEach(function(t) {
                        var bg = t.availability === 'Available' ? '#28a745' : (t.availability === 'Busy' ? '#ffc107' : '#6c757d');
                        html += '<a class="ls-item" href="' + siteUrl + '/admin/technician-view.php?id=' + t.technician_id + '">'
                            + '<div class="ls-item-icon technician"><i class="fas fa-user-cog"></i></div>'
                            + '<span class="ls-item-name">' + highlight(t.full_name, q) + '</span>'
                            + '<span class="ls-badge" style="background:' + bg + '">' + escHtml(t.availability || 'Available') + '</span>'
                            + '</a>';
                    });
                }

                dropdown.innerHTML = html;
            }

            function highlight(text, q) {
                if (!q) return escHtml(text);
                var regex = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                return escHtml(text).replace(regex, '<strong style="color:var(--primary)">$1</strong>');
            }

            function escHtml(s) {
                if (!s) return '';
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(s));
                return d.innerHTML;
            }

            // Keyboard navigation
            input.addEventListener('keydown', function(e) {
                var items = dropdown.querySelectorAll('.ls-item');
                if (!items.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                    updateActive(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, 0);
                    updateActive(items);
                } else if (e.key === 'Enter' && activeIndex >= 0) {
                    e.preventDefault();
                    items[activeIndex].click();
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('open');
                    input.blur();
                }
            });

            function updateActive(items) {
                items.forEach(function(el, i) {
                    el.classList.toggle('active', i === activeIndex);
                });
                if (items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' });
            }

            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(doSearch, 250);
            });

            input.addEventListener('focus', function() {
                if (input.value.trim().length >= 1 && dropdown.innerHTML) {
                    dropdown.classList.add('open');
                }
            });

            // Close on outside click
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#globalSearch')) {
                    dropdown.classList.remove('open');
                }
            });
        });
    })();
    ') + ')', 'gi');
                return escHtml(text).replace(regex, '<strong style="color:var(--primary)">$1</strong>');
            }

            function escHtml(s) {
                if (!s) return '';
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(s));
                return d.innerHTML;
            }

            
            input.addEventListener('keydown', function(e) {
                var items = dropdown.querySelectorAll('.ls-item');
                if (!items.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                    updateActive(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, 0);
                    updateActive(items);
                } else if (e.key === 'Enter' && activeIndex >= 0) {
                    e.preventDefault();
                    items[activeIndex].click();
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('open');
                    input.blur();
                }
            });

            function updateActive(items) {
                items.forEach(function(el, i) {
                    el.classList.toggle('active', i === activeIndex);
                });
                if (items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' });
            }

            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(doSearch, 250);
            });

            input.addEventListener('focus', function() {
                if (input.value.trim().length >= 1 && dropdown.innerHTML) {
                    dropdown.classList.add('open');
                }
            });

            
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#globalSearch')) {
                    dropdown.classList.remove('open');
                }
            });
        });
    })();
    </script>
    <?php if ($role === 'Admin'): ?>
    <script src="<?= SITE_URL ?>/admin/js/admin.js"></script>
    <?php elseif ($role === 'Technician'): ?>
    <script src="<?= SITE_URL ?>/technician/js/technician.js"></script>
    <?php elseif ($role === 'Customer'): ?>
    <script src="<?= SITE_URL ?>/customer/js/customer.js"></script>
    <?php endif; ?>
    
    <script>
        
        document.addEventListener('DOMContentLoaded', function() {
            const mobileToggle = document.getElementById('mobileToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (mobileToggle && sidebar && overlay) {
                
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('active');
                    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
                });
                
                
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                });
                
                
                const navLinks = sidebar.querySelectorAll('.nav-link');
                navLinks.forEach(function(link) {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 992) {
                            setTimeout(function() {
                                sidebar.classList.remove('open');
                                overlay.classList.remove('active');
                                document.body.style.overflow = '';
                            }, 150);
                        }
                    });
                });
                
                
                let touchStartX = 0;
                let touchEndX = 0;
                
                document.addEventListener('touchstart', function(e) {
                    touchStartX = e.changedTouches[0].screenX;
                }, { passive: true });
                
                document.addEventListener('touchend', function(e) {
                    touchEndX = e.changedTouches[0].screenX;
                    handleSwipe();
                }, { passive: true });
                
                function handleSwipe() {
                    if (window.innerWidth <= 992) {
                        
                        if (touchEndX - touchStartX > 50 && touchStartX < 50) {
                            sidebar.classList.add('open');
                            overlay.classList.add('active');
                            document.body.style.overflow = 'hidden';
                        }
                        
                        if (touchStartX - touchEndX > 50 && sidebar.classList.contains('open')) {
                            sidebar.classList.remove('open');
                            overlay.classList.remove('active');
                            document.body.style.overflow = '';
                        }
                    }
                }
                
                
                window.addEventListener('resize', function() {
                    if (window.innerWidth > 992) {
                        sidebar.classList.remove('open');
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            }
            
            
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            
            
            document.addEventListener('submit', function(e) {
                var form = e.target;
                var submitter = e.submitter;
                var confirmText = (submitter && submitter.dataset && submitter.dataset.confirm) || (form && form.dataset && form.dataset.confirm);
                if (!confirmText) return;

                if (form.dataset.swalConfirmed === 'true') {
                    form.dataset.swalConfirmed = '';
                    return;
                }
                e.preventDefault();

                var title = (submitter && submitter.dataset && submitter.dataset.confirmTitle) || (form.dataset && form.dataset.confirmTitle) || 'Are you sure?';
                var icon = (submitter && submitter.dataset && submitter.dataset.confirmIcon) || (form.dataset && form.dataset.confirmIcon) || 'warning';
                var btn = (submitter && submitter.dataset && submitter.dataset.confirmBtn) || (form.dataset && form.dataset.confirmBtn) || 'Yes, proceed!';

                
                if (submitter && submitter.name && !form.querySelector('input[type="hidden"][name="' + submitter.name + '"]')) {
                    var hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = submitter.name;
                    hiddenInput.value = submitter.value || '1';
                    form.appendChild(hiddenInput);
                }

                Swal.fire({
                    title: title,
                    text: confirmText,
                    icon: icon,
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: btn
                }).then(function(result) {
                    if (result.isConfirmed) {
                        form.dataset.swalConfirmed = 'true';
                        form.submit();
                    }
                });
            });
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>