<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Andgrow Chat Widget</title>
        @vite(['resources/js/app.js'])
        {{-- This line tells Laravel to merge and load the Vue.js and CSS files compiled by Vite. --}}
        <style>

            /* Some basic styles for the page if needed */
            body {
                font-family: sans-serif;
                margin: 0;
                padding: 0;
                background-color: #f0f2f5;
            }
            .container {
                max-width: 1200px;
                margin: 50px auto;
                padding: 20px;
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Welcome to Andgrow</h1>
            <p>This is a placeholder page for your website. The chat widget will appear in the bottom right corner.</p>
            <p>Click the chat icon to start a conversation with our AI assistant.</p>
        </div>

        <!-- The div where our Vue app will mount -->
        <div id="app">
            <chat-launcher></chat-launcher>
        </div>
    </body>
</html>
