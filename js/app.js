let currentGameId;
document.addEventListener('DOMContentLoaded', () => {
    const apiEndpoint = 'http://193.228.168.186/api.php';
    const wsEndpoint = 'ws://193.228.168.186:8081';

    // Elements
    const registerForm = document.getElementById('register-form');
    const loginForm = document.getElementById('login-form');
    const joinQueueBtn = document.getElementById('join-queue-btn');
    const gameStatus = document.getElementById('game-status');
    const questionContainer = document.getElementById('question-container');
    const sendMessageBtn = document.getElementById('send-message-btn'); // دکمه ارسال پیام

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
        const profilePicture = document.getElementById('register-profile-picture').files[0];

        if (!username || !password || !profilePicture) {
            showError('register-error', 'لطفا تمام فیلدها را پر کنید');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'register');
        formData.append('username', username);
        formData.append('password', password);
        formData.append('profile_picture', profilePicture);

        try {
            const response = await fetch(apiEndpoint, {
                method: 'POST',
                body: formData
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

    // Send Chat Message
// دکمه ارسال پیام
    sendMessageBtn?.addEventListener('click', () => {
        const message = document.getElementById('chat-message').value;
        const token = localStorage.getItem('jwt_token');

        if (!message) {
            showError('game-error', 'لطفا پیام را وارد کنید');
            return;
        }

        if (ws?.readyState === WebSocket.OPEN && currentGameId) {
            ws.send(JSON.stringify({
                action: 'send_message',
                game_id: currentGameId, // شناسه بازی فعلی
                message: message,
                token: token
            }));
            document.getElementById('chat-message').value = ''; // پاک کردن ورودی
        } else {
            showError('game-error', 'اتصال به سرور برقرار نیست یا بازی شروع نشده');
        }
    });

    // WebSocket Management
    function initWebSocket(token) {
        ws = new WebSocket(wsEndpoint);
        window.ws = ws;

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
                currentGameId = data.game_id;
                renderQuestion(data.question);
                gameStatus.textContent = 'بازی شروع شد!';
                break;

            case 'game_result':
                gameStatus.innerHTML = `
                <h3>${data.message}</h3>
                ${data.winner_id ? `<p>امتیاز شما: +20</p>` : ''}
                <button onclick="location.reload()">بازی مجدد</button>
            `;
                questionContainer.innerHTML = '';
                break;

            case 'chat_message':
                const chatMessages = document.getElementById('chat-messages');
                const messageElement = document.createElement('p');
                messageElement.textContent = `از ${data.from_username}: ${data.message}`; // نمایش نام کاربری
                chatMessages.appendChild(messageElement);
                chatMessages.scrollTop = chatMessages.scrollHeight; // اسکرول به پایین
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
                        onclick="handleAnswer('${opt}')"
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
window.handleAnswer = (answer) => {
    const ws = window.ws;
    const token = localStorage.getItem('jwt_token');

    if (ws?.readyState === WebSocket.OPEN && token && currentGameId) {
        ws.send(JSON.stringify({
            action: 'answer_question',
            game_id: currentGameId,
            answer: answer,
            token: token
        }));
    } else {
        console.error('WebSocket not open, token missing, or game_id not set');
    }
};