document.addEventListener('DOMContentLoaded', function() {
    // Show alert function
window.showAlert = function(message, type = 'success') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `fixed top-4 right-4 p-4 rounded-lg max-w-xs z-50 cursor-pointer ${
            type === 'success' ? 'bg-green-500' : 'bg-red-500'
        } text-white`;
        alertDiv.textContent = message;

        // Add click event to remove notification on click
        alertDiv.addEventListener('click', () => {
            alertDiv.remove();
        });

        document.body.appendChild(alertDiv);
        setTimeout(() => alertDiv.remove(), 3000);
    };

    // Form validation helper
    window.validateForm = function(form) {
        let isValid = true;
        form.querySelectorAll('[required]').forEach(input => {
            if (!input.value.trim()) {
                isValid = false;
                input.classList.add('border-red-500');
            } else {
                input.classList.remove('border-red-500');
            }
        });
        return isValid;
    };

    // Modal handling
    window.showAddToolModal = function() {
        document.getElementById('addToolModal').classList.remove('hidden');
    };

    window.hideAddToolModal = function() {
        document.getElementById('addToolModal').classList.add('hidden');
    };

    window.showEditToolModal = function(tool) {
        document.getElementById('editToolID').value = tool.ToolID;
        document.getElementById('editToolName').value = tool.Name;
        document.getElementById('editToolDescription').value = tool.Description;
        document.getElementById('editToolPrice').value = tool.PricePerDay;
        document.getElementById('editToolCategory').value = tool.Category;
        document.getElementById('editToolStatus').value = tool.AvailabilityStatus;
        document.getElementById('editToolModal').classList.remove('hidden');
    };

    window.hideEditToolModal = function() {
        document.getElementById('editToolModal').classList.add('hidden');
    };

    // Close modals when clicking outside
window.onclick = function(event) {
    const addModal = document.getElementById('addToolModal');
    const editModal = document.getElementById('editToolModal');
    if (event.target === addModal) {
        hideAddToolModal();
    }
    if (event.target === editModal) {
        hideEditToolModal();
    }
};

/*
// Prompt user to log in when clicking "Rent Now" or "View All" buttons on homepage
document.addEventListener('DOMContentLoaded', function() {
    // Helper function to check button text
    function isTargetButton(button) {
        return button.textContent.trim() === 'Rent Now' || button.textContent.trim() === 'View All';
    }

    // Add click listeners to buttons with class 'btn' on homepage
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        if (isTargetButton(button)) {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                window.showAlert('You must be logged in to perform this action.', 'error');
            });
        }
    });
});
*/

});
