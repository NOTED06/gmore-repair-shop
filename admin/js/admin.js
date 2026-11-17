

(function() {
    'use strict';
    
    
    document.addEventListener('DOMContentLoaded', function() {
        initModals();
        initDataTables();
        initCharts();
    });
    
    
    function initModals() {
        
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active', 'show');
            }
        });
        
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.modal.active, .modal.show');
                if (activeModal) {
                    activeModal.classList.remove('active', 'show');
                }
            }
        });
    }
    
    
    window.openModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            modal.classList.add('show');
        }
    };
    
    
    window.closeModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active', 'show');
        }
    };
    
    
    window.viewTechnician = function(technicianId) {
        window.location.href = 'technician-view.php?id=' + technicianId;
    };
    
    
    window.editTechnician = function(technicianId) {
        window.location.href = 'technician-edit.php?id=' + technicianId;
    };
    
    
    window.viewCustomer = function(customerId) {
        window.location.href = 'customer-view.php?id=' + customerId;
    };
    
    
    window.viewRepair = function(repairId) {
        window.location.href = 'repairs.php?view_id=' + repairId;
    };
    
    
    window.assignTechnician = function(repairId) {
        window.location.href = 'assign-technician.php?repair_id=' + repairId;
    };
    
    
    function initDataTables() {
        if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable) {
            jQuery('.data-table').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[0, 'desc']]
            });
        }
    }
    
    
    function initCharts() {
        
        
    }
    
    
    window.confirmDelete = function(message, formElement) {
        Swal.fire({
            title: 'Are you sure?',
            text: message || 'Are you sure you want to delete this item?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#272343',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it!'
        }).then(function(result) {
            if (result.isConfirmed && formElement) {
                formElement.submit();
            }
        });
        return false;
    };
    
})();
