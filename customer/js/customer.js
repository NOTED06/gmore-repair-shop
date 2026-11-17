

(function() {
    'use strict';
    
    
    document.addEventListener('DOMContentLoaded', function() {
        initApplianceTypeSelection();
        initFormValidation();
        initRepairFilters();
    });
    
    
    function initApplianceTypeSelection() {
        const applianceCards = document.querySelectorAll('.appliance-type-card');
        
        applianceCards.forEach(function(card) {
            const radio = card.querySelector('input[type="radio"]');
            const label = card.querySelector('.appliance-type-label');
            
            if (radio && label) {
                
                radio.addEventListener('change', function() {
                    
                    applianceCards.forEach(function(c) {
                        c.classList.remove('selected');
                    });
                    
                    
                    if (this.checked) {
                        card.classList.add('selected');
                    }
                });
                
                
                if (radio.checked) {
                    card.classList.add('selected');
                }
            }
        });
    }
    
    
    function initFormValidation() {
        const newRequestForm = document.querySelector('form[action*="new-request"]');
        
        if (newRequestForm) {
            newRequestForm.addEventListener('submit', function(e) {
                
                const applianceTypeSelected = newRequestForm.querySelector('input[name="appliance_type"]:checked');
                
                if (!applianceTypeSelected) {
                    e.preventDefault();
                    alert('Please select an appliance type.');
                    return false;
                }
                
                
                const problemDesc = newRequestForm.querySelector('textarea[name="problem_description"]');
                if (problemDesc && !problemDesc.value.trim()) {
                    e.preventDefault();
                    alert('Please provide a problem description.');
                    problemDesc.focus();
                    return false;
                }
            });
        }
    }
    
    
    function initRepairFilters() {
        const filterPills = document.querySelectorAll('.pill');
        
        filterPills.forEach(function(pill) {
            pill.addEventListener('click', function(e) {
                e.preventDefault();
                const status = this.dataset.status;
                
                if (status) {
                    window.location.href = 'repairs.php?status=' + encodeURIComponent(status);
                } else {
                    window.location.href = 'repairs.php';
                }
            });
        });
    }
    
    
    window.viewRepairDetail = function(repairId) {
        window.location.href = 'repair-detail.php?id=' + repairId;
    };
    
    
    window.cancelRepair = function(repairId) {
        if (confirm('Are you sure you want to cancel this repair request?')) {
            window.location.href = 'cancel-request.php?id=' + repairId;
        }
    };
    
    
    window.calculateEstimate = function() {
        
        console.log('Estimate calculation functionality');
    };
    
    
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(function(textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    });
    
})();
