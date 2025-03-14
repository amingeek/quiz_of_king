let currentGameId;
let currentRound = 1;

document.addEventListener('DOMContentLoaded', () => {
    const apiEndpoint = 'http://193.228.168.186/api.php';
    const wsEndpoint = 'ws://193.228.168.186:8081';

    const registerForm = document.getElementById('register-form');
    const loginForm = document.getElementById('login-form');
    const joinQueueBtn = document.getElementById('join-queue-btn');
    const gameStatus = document.getElementById('game-status');
    const questionContainer = document.getElementById('question-container');
    const sendMessageBtn = document.getElementById('send-message-btn');
    const playersInfo = document.getElementById('players-info');

    const sections = {
        register: document.getElementById('register-section'),
        login: document.getElementById('login-section'),
        game: document.getElementById('game-section')
    };

    let ws;

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

    loginForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('login-username').value;
        const password = document.getElementById('login-password').value;

        if (!username || !password) {
            showError('login-error', 'لطفا تمام فیلدها را پر کنید');
            return;
        }

        const payload = {
            action: 'login',
            username: username,
            password: password
        };

        try {
            const response = await fetch(apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
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

    sendMessageBtn?.addEventListener('click', () => {
        const message = document.getElementById('chat-message').value;
        const token = localStorage.getItem('jwt_token');

        if (!message) {
            showError('game-error', 'لطفا پیام را وارد کنید');
            return;
        }

        if (ws?.readyState === WebSocket.OPEN && currentGameId) {
            const payload = {
                action: 'send_message',
                game_id: currentGameId,
                message: message,
                token: token
            };

            ws.send(JSON.stringify(payload));
            document.getElementById('chat-message').value = '';
        } else {
            showError('game-error', 'اتصال به سرور برقرار نیست یا بازی شروع نشده');
        }
    });

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

    function handleSocketMessage(data) {
        switch (data.type) {
            case 'status':
                gameStatus.textContent = data.message;
                break;

            case 'answer_received':
                gameStatus.textContent = data.message;
                break;

            case 'players_matched':
                playersInfo.style.display = 'block';
                document.getElementById('player1-username').textContent = data.players.player1.username;
                document.getElementById('player1-picture').src = `http://193.228.168.186/uploads/${data.players.player1.profile_picture}`;
                document.getElementById('player2-username').textContent = data.players.player2.username;
                document.getElementById('player2-picture').src = `http://193.228.168.186/uploads/${data.players.player2.profile_picture}`;
                gameStatus.textContent = 'حریف پیدا شد! آماده بازی...';
                break;

            case 'game_start':
                currentGameId = data.game_id;
                currentRound = 1;
                renderQuestion(data.question);
                gameStatus.textContent = 'بازی شروع شد! مرحله ۱ از ۵';
                break;

            case 'round_result':
                gameStatus.textContent = `مرحله ${data.round} از ۵ - ${data.message}`;
                break;

            case 'next_round':
                currentRound = data.round;
                renderQuestion(data.question);
                gameStatus.textContent = `مرحله ${currentRound} از ۵`;
                document.querySelectorAll('.option').forEach(btn => btn.disabled = false);
                break;

            case 'game_result':
                gameStatus.innerHTML = `
                    <h3>${data.message}</h3>
                    <p>امتیاز شما در این بازی: ${data.your_score} از ۵</p>
                    <p>امتیاز حریف در این بازی: ${data.opponent_score} از ۵</p>
                    <p>امتیاز کل شما: ${data.total_score}</p>
                    <button onclick="location.reload()">بازی مجدد</button>
                `;
                questionContainer.innerHTML = '';
                playersInfo.style.display = 'none';
                break;

            case 'chat_message':
                const chatMessages = document.getElementById('chat-messages');
                const messageElement = document.createElement('p');
                messageElement.textContent = `از ${data.from_username}: ${data.message}`;
                chatMessages.appendChild(messageElement);
                chatMessages.scrollTop = chatMessages.scrollHeight;
                break;

            case 'error':
                showError('game-error', data.message);
                break;

            default:
                console.warn('Unknown message type:', data);
        }
    }

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

    function showSection(section) {
        Object.values(sections).forEach(el => el.style.display = 'none');
        if (sections[section]) sections[section].style.display = 'block';
    }

    function showError(elementId, message) {
        const errorElement = document.getElementById(elementId);
        errorElement.textContent = message;
        errorElement.style.display = 'block';
        setTimeout(() => errorElement.style.display = 'none', 5000);
    }
});

function decodeJWT(token) {
    try {
        const base64Url = token.split('.')[1];
        const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
        const jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
            return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
        return JSON.parse(jsonPayload);
    } catch (e) {
        return null;
    }
}

window.handleAnswer = (answer) => {
    const ws = window.ws;
    const token = localStorage.getItem('jwt_token');

    if (ws?.readyState === WebSocket.OPEN && token && currentGameId) {
        const payload = {
            action: 'answer_question',
            game_id: currentGameId,
            answer: answer,
            token: token
        };
        ws.send(JSON.stringify(payload));
        document.querySelectorAll('.option').forEach(btn => btn.disabled = true);
    } else {
        console.error('WebSocket not open, token missing, or game_id not set');
    }
};