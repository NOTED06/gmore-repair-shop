

document.addEventListener('DOMContentLoaded', function() {
    initPasswordToggles();
    initFormValidation();
    initAlertDismiss();
    initFormAnimations();
});

function initPasswordToggles() {
    const toggleButtons = document.querySelectorAll('.password-toggle');
    
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const wrapper = this.closest('.input-wrapper');
            const input = wrapper.querySelector('input');
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
}

function initFormValidation() {
    const forms = document.querySelectorAll('.auth-form');
    
    forms.forEach(form => {
        const inputs = form.querySelectorAll('.form-control');
        
        
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateInput(this);
            });
            
            input.addEventListener('input', function() {
                
                this.classList.remove('is-invalid');
                const wrapper = this.closest('.input-wrapper');
                if (wrapper) {
                    wrapper.classList.remove('has-error');
                }
            });
        });
        
        
        const password = form.querySelector('#password');
        const confirmPassword = form.querySelector('#confirm_password');
        
        if (password && confirmPassword) {
            confirmPassword.addEventListener('input', function() {
                if (this.value && password.value !== this.value) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            });
        }
        
        
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            let isValid = true;
            
            inputs.forEach(input => {
                if (!validateInput(input)) {
                    isValid = false;
                }
            });
            
            if (isValid) {
                
                if (submitBtn) {
                    submitBtn.classList.add('loading');
                    submitBtn.disabled = true;
                }
            } else {
                e.preventDefault();
            }
        });
    });
}

function validateInput(input) {
    const value = input.value.trim();
    const type = input.type;
    const required = input.hasAttribute('required');
    const wrapper = input.closest('.input-wrapper');
    
    let isValid = true;
    
    
    if (required && !value) {
        isValid = false;
    }
    
    
    if (type === 'email' && value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            isValid = false;
        }
    }
    
    
    if (input.id === 'password' && value && value.length < 6) {
        isValid = false;
    }
    
    
    if (input.id === 'username' && value && value.length < 3) {
        isValid = false;
    }
    
    
    if (!isValid) {
        input.classList.add('is-invalid');
        if (wrapper) {
            wrapper.classList.add('has-error');
        }
    } else {
        input.classList.remove('is-invalid');
        if (wrapper) {
            wrapper.classList.remove('has-error');
        }
    }
    
    return isValid;
}

function initAlertDismiss() {
    const alerts = document.querySelectorAll('.alert');
    
    alerts.forEach(alert => {
        
        const closeBtn = document.createElement('button');
        closeBtn.className = 'alert-close';
        closeBtn.innerHTML = '<i class="fas fa-times"></i>';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.onclick = () => dismissAlert(alert);
        alert.appendChild(closeBtn);
        
        
        if (alert.classList.contains('alert-success')) {
            setTimeout(() => dismissAlert(alert), 8000);
        }
    });
}

function dismissAlert(alert) {
    alert.style.opacity = '0';
    alert.style.transform = 'translateY(-10px)';
    alert.style.transition = 'all 0.3s ease';
    
    setTimeout(() => {
        alert.remove();
    }, 300);
}

function initFormAnimations() {
    const formGroups = document.querySelectorAll('.form-group');
    
    formGroups.forEach((group, index) => {
        group.style.opacity = '0';
        group.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            group.style.transition = 'all 0.3s ease';
            group.style.opacity = '1';
            group.style.transform = 'translateY(0)';
        }, 100 + (index * 50));
    });
}

function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const wrapper = field.closest('.input-wrapper');
    const icon = wrapper.querySelector('.password-toggle i');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

const style = document.createElement('style');
style.textContent = `
    .form-control.is-invalid {
        border-color: var(--danger) !important;
    }
    
    .form-control.is-invalid:focus {
        box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.2) !important;
    }
    
    .input-wrapper.has-error .input-icon {
        color: var(--danger) !important;
    }
    
    .alert-close {
        position: absolute;
        top: 50%;
        right: 1rem;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        opacity: 0.5;
        color: inherit;
        padding: 0.25rem;
        line-height: 1;
    }
    
    .alert-close:hover {
        opacity: 1;
    }
    
    .alert {
        position: relative;
        padding-right: 3rem;
    }
`;
document.head.appendChild(style);
