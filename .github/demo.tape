Output .github/demo.gif

Set Shell "bash"
Set FontSize 18
Set Width 1040
Set Height 470
Set Padding 24
Set TypingSpeed 45ms
Set Theme '{ "name": "bw", "background": "#000000", "foreground": "#e6e6e6", "cursor": "#e6e6e6", "selection": "#3a3a3a", "black": "#000000", "red": "#e6e6e6", "green": "#e6e6e6", "yellow": "#e6e6e6", "blue": "#e6e6e6", "magenta": "#e6e6e6", "cyan": "#e6e6e6", "white": "#e6e6e6", "brightBlack": "#8a8a8a", "brightRed": "#ffffff", "brightGreen": "#ffffff", "brightYellow": "#ffffff", "brightBlue": "#ffffff", "brightMagenta": "#ffffff", "brightCyan": "#ffffff", "brightWhite": "#ffffff" }'

# Hidden setup: minimal prompt, and a php-dag shim that runs the real binary
# inside DDEV (regenerate with: vhs .github/demo.tape).
Hide
Type "export PATH=/opt/homebrew/bin:$PATH" Enter
Type "export PS1='$ '" Enter
Type `php-dag() { ddev exec php bin/php-dag "$@"; }` Enter
Type "clear" Enter
Show

Sleep 700ms
Type "php-dag examples/dependencies.dot"
Sleep 600ms
Enter
Sleep 4s

Hide
Type "clear" Enter
Show

Sleep 400ms
Type "php-dag --direction=lr examples/workflow.dot"
Sleep 600ms
Enter
Sleep 4s
