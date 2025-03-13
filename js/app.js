document.addEventListener('DOMContentLoaded', () => {
    const apiEndpoint = 'http://193.228.168.186/api.php';
    const wsEndpoint = 'ws://193.228.168.186:8081';

    // Elements
    const registerForm = document.getElementById('register-form');
    const loginForm = document.getElementById('login-form');
    const joinQueueBtn = document.getElementById('join-queue-btn');
    const gameStatus = document.getElementById('game-status');
    const questionContainer = document.getElementById('question-container');

    // Sections
    const sections = {
        register: document.getElementById('register-section'),
        login: document.getElementById('login-section'),
        game: document.getElementById('game-section')
    };

    let ws;

    // Register Form
    registerForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('register-username').value;
        const password = document.getElementById('register-password').value;

        if (!username || !password) {
            showError('register-error', 'لطفا تمام فیلدها را پر کنید');
            return;
        }

        try {
            const response = await fetch(apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'register', username, password })
            });

            const data = await response.json();
            if (data.status === 'success') {
                showSection('login');
            } else {
                showError('register-error', data.error || 'خطا در ثبت نام');
            }
        } catch (error) {
            showError('register-error', 'خطا در ارتباط با سرور');
        }
    });

    // Login Form
    loginForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('login-username').value;
        const password = document.getElementById('login-password').value;

        if (!username || !password) {
            showError('login-error', 'لطفا تمام فیلدها را پر کنید');
            return;
        }

        try {
            const response = await fetch(apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'login', username, password })
            });

            const data = await response.json();
            if (data.token) {
                localStorage.setItem('jwt_token', data.token);
                initWebSocket(data.token);
                showSection('game');
            } else {
                showError('login-error', data.error || 'خطا در ورود');
            }
        } catch (error) {
            showError('login-error', 'خطا در ارتباط با سرور');
        }
    });

    // Join Queue
    joinQueueBtn?.addEventListener('click', () => {
        const token = localStorage.getItem('jwt_token');
        if (!token) {
            showError('game-error', 'لطفا ابتدا وارد شوید');
            return;
        }

        if (ws?.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ action: 'join_queue', token }));
            gameStatus.textContent = 'در حال پیدا کردن حریف...';
        }
    });

    // WebSocket Management
    function initWebSocket(token) {
        ws = new WebSocket(wsEndpoint);

        ws.onopen = () => {
            console.log('WebSocket Connected');
            ws.send(JSON.stringify({ action: 'auth', token }));
        };

        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                handleSocketMessage(data);
            } catch (error) {
                console.error('Error parsing message:', error);
            }
        };

        ws.onerror = (error) => {
            console.error('WebSocket Error:', error);
            showError('game-error', 'خطا در اتصال به سرور');
        };

        ws.onclose = () => {
            console.log('WebSocket Closed');
        };
    }

    // Handle WebSocket Messages
    function handleSocketMessage(data) {
        switch (data.type) {
            case 'status':
                gameStatus.textContent = data.message;
                break;

            case 'game_start':
                renderQuestion(data.question);
                gameStatus.textContent = 'بازی شروع شد!';
                break;

// در تابع handleSocketMessage
            case 'game_result':
                gameStatus.innerHTML = `
                    <h3>${data.message}</h3>
                    ${data.winner_id ? `<p>امتیاز شما: +20</p>` : ''}
                    <button onclick="location.reload()">بازی مجدد</button>
    `;
                questionContainer.innerHTML = '';
                break;
            case 'error':
                showError('game-error', data.message);
                break;

            default:
                console.warn('Unknown message type:', data);
        }
    }

    // Render Question
    function renderQuestion(question) {
        questionContainer.innerHTML = `
            <h3>${question.question}</h3>
            <div class="options">
                ${['option1', 'option2', 'option3', 'option4'].map(opt => `
                    <button 
                        class="option"
                        onclick="handleAnswer('${question.id}', '${opt}')"
                    >
                        ${question[opt]}
                    </button>
                `).join('')}
            </div>
        `;
    }

    // Show/Hide Sections
    function showSection(section) {
        Object.values(sections).forEach(el => el.style.display = 'none');
        if (sections[section]) sections[section].style.display = 'block';
    }

    // Error Handling
    function showError(elementId, message) {
        const errorElement = document.getElementById(elementId);
        errorElement.textContent = message;
        errorElement.style.display = 'block';
        setTimeout(() => errorElement.style.display = 'none', 5000);
    }
});

// Global Answer Handler
window.handleAnswer = (questionId, answer) => {
    const ws = window.ws;
    const token = localStorage.getItem('jwt_token');

    if (ws?.readyState === WebSocket.OPEN && token) {
        ws.send(JSON.stringify({
            action: 'answer_question',
            question_id: questionId,
            answer: answer,
            token: token
        }));
    }
};