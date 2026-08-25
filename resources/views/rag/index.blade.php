<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Laravel RAG Assistant</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 h-screen flex flex-col font-sans overflow-hidden">

    <!-- Header -->
    <header class="bg-slate-900 text-white px-6 py-4 flex justify-between items-center shadow">
        <div>
            <h1 class="text-xl font-bold tracking-wide">Laravel RAG Assistant</h1>
            <p class="text-xs text-slate-400">PostgreSQL Vector Search & LLM</p>
        </div>
        <form action="{{ route('rag.clear') }}" method="POST">
            @csrf
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-sm px-3.5 py-1.5 rounded-md font-medium transition">
                Clear Chat History
            </button>
        </form>
    </header>

    <div class="flex flex-1 overflow-hidden relative">
        
        <!-- Left Sidebar -->
        <aside class="w-1/3 bg-white border-r border-slate-200 p-6 flex flex-col overflow-y-auto">
            <h2 class="text-lg font-semibold text-slate-800 mb-4">Upload Documents</h2>

            @if(session('success'))
                <div class="bg-green-100 border border-green-200 text-green-700 p-3 rounded-md text-sm mb-4">
                    {{ session('success') }}
                </div>
            @endif

            <form action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data" class="mb-6 border-2 border-dashed border-slate-300 p-4 rounded-lg bg-slate-50 text-center hover:bg-slate-100 transition">
                @csrf
                
                <!-- NEW CATEGORY DROPDOWN -->
                <select name="category" class="w-full mb-3 border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-blue-500 text-slate-600 bg-white" required>
                    <option value="" disabled selected>Select Document Category...</option>
                    <option value="General">General Knowledge</option>
                    <option value="Tech">Technical & IT</option>
                    <option value="HR">Human Resources</option>
                    <option value="Finance">Finance & Billing</option>
                </select>

                <input type="file" name="document" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 mb-3 cursor-pointer" required />
                
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-md text-sm transition">
                    Upload & Process Chunks
                </button>
            </form>

            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Indexed Documents ({{ $documents->count() }})</h3>
            <div class="space-y-3 flex-1 overflow-y-auto">
                @forelse($documents as $doc)
                    <div class="bg-slate-50 border border-slate-200 p-3 rounded-lg flex justify-between items-center">
                        <div class="truncate pr-2">
                            <div class="flex items-center gap-2 mb-0.5">
                                <p class="text-sm font-medium text-slate-800 truncate">{{ $doc->title }}</p>
                                <span class="text-[10px] bg-slate-200 text-slate-600 px-1.5 py-0.5 rounded uppercase font-bold">{{ $doc->category }}</span>
                            </div>
                            <span class="text-xs text-slate-500">{{ $doc->chunks()->count() }} Chunks Indexed</span>
                        </div>
                        <form action="{{ route('documents.destroy', $doc->id) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-semibold">Delete</button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-slate-400 italic">No documents uploaded yet.</p>
                @endforelse
            </div>
        </aside>

        <!-- Right Main Chat -->
        <main class="w-2/3 flex flex-col bg-slate-50 justify-between">
            <div id="chat-window" class="flex-1 p-6 overflow-y-auto space-y-4">
                @forelse($messages as $msg)
                    @if($msg->role === 'user')
                        <div class="flex justify-end">
                            <div class="bg-blue-600 text-white rounded-lg px-4 py-2.5 max-w-xl text-sm shadow">
                                <p class="font-bold text-xs text-blue-200 mb-0.5">You</p>
                                {{ $msg->content }}
                            </div>
                        </div>
                    @else
                        <div class="flex justify-start">
                            <div class="bg-white border border-slate-200 text-slate-800 rounded-lg px-4 py-2.5 max-w-xl text-sm shadow">
                                <p class="font-bold text-xs text-blue-600 mb-0.5">AI Assistant</p>
                                <p class="whitespace-pre-line">{{ $msg->content }}</p>
                                @if($msg->source_info)
                                    <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between">
                                        <span class="text-xs text-slate-500 font-medium">📎 {{ $msg->source_info }}</span>
                                        @if($msg->raw_chunks)
                                            <button onclick="openDrawer('hist-{{ $msg->id }}')" class="text-xs text-blue-600 hover:text-blue-800 font-semibold underline ml-2">
                                                Inspect Chunks & JSON &rarr;
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @empty
                    <div id="empty-state" class="text-center text-slate-400 mt-20">
                        <p class="text-base font-medium">No conversation yet.</p>
                        <p class="text-sm">Upload documents on the left and ask your questions below!</p>
                    </div>
                @endforelse
            </div>

            <!-- Input Bar -->
            <div class="bg-white border-t border-slate-200 p-4 shadow-sm">
                <div class="flex gap-2 max-w-5xl mx-auto">
                    <select id="model-select" class="border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-blue-500 bg-slate-50 font-semibold text-slate-700 cursor-pointer">
                        <option value="ollama">Local (Ollama phi3)</option>
                        <option value="gemini">Live (Gemini Flash)</option>
                    </select>

                    <input type="text" id="question-input" class="flex-1 border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-blue-500" placeholder="Ask a question about your uploaded documents...">
                    
                    <button id="send-btn" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-medium transition flex items-center justify-center">
                        Send
                    </button>
                </div>
            </div>
        </main>

        <!-- Slide-in Inspector Drawer (Right popup) -->
        <div id="drawer" class="fixed inset-y-0 right-0 w-96 bg-white shadow-2xl border-l border-slate-200 transform translate-x-full transition-transform duration-300 ease-in-out z-50 flex flex-col">
            <div class="p-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                <div>
                    <h3 class="font-bold text-slate-800 text-sm">Retrieved Context Inspector</h3>
                    <p class="text-xs text-slate-500">pgvector Top Matches</p>
                </div>
                <button onclick="closeDrawer()" class="text-slate-400 hover:text-slate-600 text-lg font-bold">&times;</button>
            </div>
            
            <div class="flex-1 p-4 overflow-y-auto space-y-4" id="drawer-content">
                <!-- Dynamically populated -->
            </div>
        </div>

    </div>

    <!-- Client-side Script -->
    <script>
        const chatWindow = document.getElementById('chat-window');
        const questionInput = document.getElementById('question-input');
        const sendBtn = document.getElementById('send-btn');
        const modelSelect = document.getElementById('model-select');
        const drawer = document.getElementById('drawer');
        const drawerContent = document.getElementById('drawer-content');

        // Pre-load historical chunks from the server into the JS store
        const chunkStore = {};
        @foreach($messages as $msg)
            @if($msg->role === 'assistant' && !empty($msg->raw_chunks))
                chunkStore['hist-{{ $msg->id }}'] = @json($msg->raw_chunks);
            @endif
        @endforeach

        chatWindow.scrollTop = chatWindow.scrollHeight;

        sendBtn.addEventListener('click', sendMessage);
        questionInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') sendMessage();
        });

        function openDrawer(responseId) {
            const data = chunkStore[responseId];
            if (!data) return;

            drawerContent.innerHTML = `
                <div class="mb-4">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Total Chunks Found:</span>
                    <span class="text-xs font-semibold bg-blue-100 text-blue-800 px-2 py-0.5 rounded ml-1">${data.length}</span>
                </div>
                ${data.map((c, i) => `
                    <div class="border border-slate-200 rounded-lg p-3 bg-slate-50 text-xs space-y-1.5">
                        <div class="flex justify-between items-center font-bold text-slate-700">
                            <span>#${i + 1} - Chunk ID: ${c.id}</span>
                            <span class="text-blue-600 truncate max-w-[120px]">${escapeHtml(c.document)}</span>
                        </div>
                        <p class="text-slate-600 whitespace-pre-line bg-white p-2 rounded border border-slate-100">${escapeHtml(c.content)}</p>
                    </div>
                `).join('')}
                <div class="pt-4 border-t border-slate-200">
                    <span class="text-xs font-bold text-slate-500 uppercase">Raw JSON Payload</span>
                    <pre class="bg-slate-900 text-emerald-400 p-2.5 rounded text-[11px] overflow-x-auto mt-2">${escapeHtml(JSON.stringify(data, null, 2))}</pre>
                </div>
            `;

            drawer.classList.remove('translate-x-full');
        }

        function closeDrawer() {
            drawer.classList.add('translate-x-full');
        }

        // --- Smooth Typewriter Engine ---
        function typeWriterEffect(element, text, speed = 15, onComplete = null) {
            let i = 0;
            element.textContent = '';
            
            // Add animated cursor
            const cursor = document.createElement('span');
            cursor.className = 'inline-block w-2 h-4 bg-blue-600 ml-0.5 animate-pulse align-middle';
            element.parentNode.appendChild(cursor);

            function type() {
                if (i < text.length) {
                    element.textContent += text.charAt(i);
                    i++;
                    chatWindow.scrollTop = chatWindow.scrollHeight;
                    setTimeout(type, speed);
                } else {
                    cursor.remove();
                    if (onComplete) onComplete();
                }
            }
            type();
        }

        async function sendMessage() {
            const question = questionInput.value.trim();
            if (!question) return;

            const emptyState = document.getElementById('empty-state');
            if (emptyState) emptyState.remove();

            const selectedModel = modelSelect.value;

            // Lock inputs
            sendBtn.disabled = true;
            questionInput.disabled = true;
            modelSelect.disabled = true;
            sendBtn.classList.add('opacity-50', 'cursor-not-allowed');

            // 1. Render User Message
            chatWindow.innerHTML += `
                <div class="flex justify-end">
                    <div class="bg-blue-600 text-white rounded-lg px-4 py-2.5 max-w-xl text-sm shadow">
                        <p class="font-bold text-xs text-blue-200 mb-0.5">You (${selectedModel.toUpperCase()})</p>
                        ${escapeHtml(question)}
                    </div>
                </div>
            `;
            questionInput.value = '';
            chatWindow.scrollTop = chatWindow.scrollHeight;

            const responseKey = 'res-' + Date.now();
            const loaderId = 'loader-' + responseKey;

            // 2. Render AI Message Bubble WITH Loader Inside
            const msgWrapper = document.createElement('div');
            msgWrapper.className = 'flex justify-start';
            msgWrapper.innerHTML = `
                <div class="bg-white border border-slate-200 text-slate-800 rounded-lg px-4 py-2.5 max-w-xl w-full text-sm shadow">
                    <p class="font-bold text-xs text-blue-600 mb-1.5">AI Assistant (${selectedModel.toUpperCase()})</p>
                    
                    <!-- The Loader -->
                    <div id="${loaderId}" class="flex items-center gap-2 text-slate-500 py-1">
                        <svg class="animate-spin h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span class="text-xs font-medium animate-pulse">Thinking & searching documents...</span>
                    </div>

                    <!-- The Text Target (Hidden initially) -->
                    <p id="text-${responseKey}" class="whitespace-pre-line text-slate-700"></p>
                    
                    <!-- The Footer (Hidden initially) -->
                    <div id="footer-${responseKey}" class="mt-2 pt-2 border-t border-slate-100 hidden">
                        <div class="flex items-center justify-between">
                            <span id="source-${responseKey}" class="text-xs text-slate-500 font-medium"></span>
                            <button onclick="openDrawer('${responseKey}')" class="text-xs text-blue-600 hover:text-blue-800 font-semibold underline ml-2">
                                Inspect Chunks & JSON &rarr;
                            </button>
                        </div>
                    </div>
                </div>
            `;
            chatWindow.appendChild(msgWrapper);
            chatWindow.scrollTop = chatWindow.scrollHeight;

            const textTarget = document.getElementById(`text-${responseKey}`);
            const footerTarget = document.getElementById(`footer-${responseKey}`);
            const sourceTarget = document.getElementById(`source-${responseKey}`);
            const loaderElement = document.getElementById(loaderId);

            try {
                // 3. Fetch from Server
                const response = await fetch("{{ route('rag.chat') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({ question, ai_model: selectedModel })
                });

                const data = await response.json();

                // 4. Remove Loader once data arrives
                if (loaderElement) loaderElement.remove();

                if (data.raw_chunks && data.raw_chunks.length > 0) {
                    chunkStore[responseKey] = data.raw_chunks;
                }

                // 5. Start Typewriter Effect in the same bubble
                typeWriterEffect(textTarget, data.answer || 'No response generated.', 12, () => {
                    // Show footer only after typing is complete
                    if (data.source) {
                        sourceTarget.innerHTML = `📎 ${escapeHtml(data.source)}`;
                        footerTarget.classList.remove('hidden');
                    }
                    chatWindow.scrollTop = chatWindow.scrollHeight;
                });

            } catch (error) {
                if (loaderElement) loaderElement.remove();
                textTarget.textContent = 'An error occurred while communicating with the server.';
                console.error(error);
            } finally {
                // Unlock inputs
                sendBtn.disabled = false;
                questionInput.disabled = false;
                modelSelect.disabled = false;
                sendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                questionInput.focus();
            }
        }

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return (text || '').replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>