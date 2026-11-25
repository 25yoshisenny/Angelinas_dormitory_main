(() => {
    const root = document.getElementById('dorm-chat');
    if (!root) return;

    const panel = document.getElementById('dorm-chat-panel');
    const launcher = root.querySelector('.dorm-chat-launcher');
    const close = root.querySelector('.dorm-chat-close');
    const messages = document.getElementById('dorm-chat-messages');
    const questions = document.getElementById('dorm-chat-questions');
    const faq = [
        {
            question: 'How do I create an account?',
            answer: 'Choose Get Started to register with your name, email, contact number, username, and password. Registration creates a tenant account; it does not reserve a bed or assign a room.',
            link: { label: 'Create an account', href: '?page=register' }
        },
        {
            question: 'How do room assignments work?',
            answer: 'Room and bed assignments are handled by dormitory administration after registration. Please contact the office to ask about availability or your assignment.',
            link: { label: 'Contact the office', href: '#contact' }
        },
        {
            question: 'Where can I see my bill?',
            answer: 'Sign in to your tenant account to review your billing statements and due dates. If you do not have an account yet, register first.',
            link: { label: 'Sign in', href: '?page=login' }
        },
        {
            question: 'How are payments confirmed?',
            answer: 'Payment details submitted through the portal are not confirmed immediately. The office verifies the funds before updating your balance and issuing a receipt. A QR scan does not transfer money automatically.',
            link: { label: 'Sign in to your account', href: '?page=login' }
        },
        {
            question: 'How do I contact the dormitory?',
            answer: 'Send a message using the contact form below, or email support@angelinasdormitory.com. Please do not include your password or payment credentials.',
            link: { label: 'Go to the contact form', href: '#contact' }
        }
    ];

    const addMessage = (text, className) => {
        const message = document.createElement('p');
        message.className = `dorm-chat-message ${className}`;
        message.textContent = text;
        messages.append(message);
    };

    const showQuestions = () => {
        questions.replaceChildren();
        faq.forEach((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dorm-chat-question';
            button.textContent = item.question;
            button.addEventListener('click', () => {
                addMessage(item.question, 'user');
                addMessage(item.answer, 'assistant');
                const link = document.createElement('a');
                link.className = 'dorm-chat-link';
                link.href = item.link.href;
                link.textContent = item.link.label;
                messages.append(link);
                questions.replaceChildren();
                showQuestions();
                questions.querySelectorAll('button')[index].focus();
                messages.scrollTop = messages.scrollHeight;
            });
            questions.append(button);
        });
    };

    addMessage("Hi! I can help with Angelina's Dormitory system. Choose a question to get started.", 'assistant');
    showQuestions();

    const setOpen = open => {
        panel.hidden = !open;
        launcher.setAttribute('aria-expanded', String(open));
        if (open) root.querySelector('.dorm-chat-question').focus();
        else launcher.focus();
    };
    launcher.addEventListener('click', () => setOpen(panel.hidden));
    close.addEventListener('click', () => setOpen(false));
})();
