

(function() {
    'use strict';
    
    
    document.addEventListener('DOMContentLoaded', function() {
        initStatusModals();
        initAvailabilityToggle();
    });
    
    
    function initStatusModals() {
        
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('status-modal')) {
                e.target.classList.remove('active');
            }
        });
        
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.status-modal.active');
                if (activeModal) {
                    activeModal.classList.remove('active');
                }
            }
        });
    }
    
    
    window.openStatusModal = function(repairId, currentStatus, appliance) {
        const modal = document.getElementById('statusModal');
        if (!modal) {
            console.error('Modal not found!');
            return;
        }
        
        
        const repairIdInput = document.getElementById('repair_id');
        const modalRepairId = document.getElementById('modal_repair_id');
        const modalAppliance = document.getElementById('modal_appliance');
        const modalCurrentStatus = document.getElementById('modal_current_status');
        
        if (repairIdInput) repairIdInput.value = repairId;
        if (modalRepairId) modalRepairId.textContent = '#' + repairId;
        if (modalAppliance) modalAppliance.textContent = appliance || '';
        if (modalCurrentStatus) modalCurrentStatus.textContent = currentStatus || '';
        
        
        const form = document.getElementById('statusUpdateForm');
        if (form) {
            form.reset();
            if (repairIdInput) repairIdInput.value = repairId;
        }
        
        
        modal.style.display = 'flex';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    };
    
    
    window.closeStatusModal = function() {
        const modal = document.getElementById('statusModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    };
    
    
    window.viewRepairDetail = function(repairId) {
        window.location.href = 'repair-detail.php?id=' + repairId;
    };
    
    
    window.updateStatus = function(repairId) {
        window.location.href = 'update-status.php?id=' + repairId;
    };
    
    
    function initAvailabilityToggle() {
        const availabilitySelect = document.querySelector('select[name="availability"]');
        if (availabilitySelect) {
            availabilitySelect.addEventListener('change', function() {
                
                const formGroup = this.closest('.availability-control');
                if (formGroup) {
                    formGroup.style.opacity = '0.6';
                }
            });
        }
    }
    
    
    window.confirmStatusUpdate = function(status) {
        return confirm('Are you sure you want to update the status to "' + status + '"?');
    };
    
    
    window.quickUpdateStatus = function(repairId, newStatus) {
        if (confirm('Update status to ' + newStatus + '?')) {
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'process-status-update.php';
            
            const repairIdInput = document.createElement('input');
            repairIdInput.type = 'hidden';
            repairIdInput.name = 'repair_id';
            repairIdInput.value = repairId;
            
            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'repair_status';
            statusInput.value = newStatus;
            
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = 'csrf_token';
                tokenInput.value = csrfToken.value;
                form.appendChild(tokenInput);
            }
            
            form.appendChild(repairIdInput);
            form.appendChild(statusInput);
            document.body.appendChild(form);
            form.submit();
        }
    };
    
})();
