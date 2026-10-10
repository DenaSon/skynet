<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in — {{ config('app.name') }}</title>
</head>
<body>
    <main>
        <h1>SkyNet sign in</h1>
        <form method="post" action="{{ route('identity.login') }}">
            @csrf
            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
            </div>
            @error('email')
                <p role="alert">{{ $message }}</p>
            @enderror
            <button type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
