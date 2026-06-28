<?php
	session_start();
	if (empty($_SESSION["csrf_token"])) {
		$_SESSION["csrf_token"] = bin2hex(random_bytes(32));
	}

	$env_path = __DIR__ . "/../.env";
	if (file_exists($env_path)) {
		$lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		foreach ($lines as $line) {
			[$key, $value] = explode("=", $line, 2);
			$_ENV[$key] = $value;
		}
	}

	try {
		$host = $_ENV["DB_HOST"];
		$name = $_ENV["DB_NAME"];
		$user = $_ENV["DB_USER"];
		$password = $_ENV["DB_PASSWORD"];

		$db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $password);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	} catch (PDOException $e) {
		error_log("Failed to connect to database: " . $e->getMessage());
		http_response_code(500);
		die("Something went wrong, please try again later.");
	}

	$message_max_length = 200;
	$name_max_length = 100;
	$email_max_length = 100;

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		$token = $_POST["csrf_token"] ?? "";
		if (!hash_equals($_SESSION["csrf_token"], $token)) {
			http_response_code(403);
			die("Invalid request.");
		}

		$name = trim($_POST["name"] ?? "");
		$email = trim($_POST["email"] ?? "");
		$message = trim($_POST["message"] ?? "");

		if ($name !== "" && $email !== "" && $message !== "") {
			$name = mb_substr($name, 0, $name_max_length, "UTF-8");
			$email = mb_substr($email, 0, $email_max_length, "UTF-8");
			$message = mb_substr($message, 0, $message_max_length, "UTF-8");

			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				header("Location: /?error=invalid_email");
				exit;
			}

			$stmt = $db->prepare(
				"INSERT INTO entries (name, email, message) VALUES (:name, :email, :message)"
			);
			$stmt->bindParam(":name", $name);
			$stmt->bindParam(":email", $email);
			$stmt->bindParam(":message", $message);
			$stmt->execute();
		}

		header("Location: /");
		exit;
	}

	$error = $_GET["error"] ?? null;

	$stmt = $db->query("SELECT name, message, created_at FROM entries ORDER BY id DESC LIMIT 25");
	$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />

		<title>Hall of Words</title>

		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,200..900;1,8..60,200..900&display=swap" rel="stylesheet">

		<style>
			* {
				font-family: inherit;
				font-weight: inherit;
				font-size: inherit;

				margin: 0;
				padding: 0;
				box-sizing: border-box;

				background: none;
				border: none;
				border-radius: 0px;
			}

			html { font-family: "Source Serif 4", serif; font-weight: normal; font-size: 12px; }
			body { width: 100%; min-height: 100dvh; padding: 4rem 1rem; background-color: #f8f8fc; }
			footer { max-width: 400px; }
			textarea { resize: none; }

			.w-full { width: 100%; }

			.flex { display: flex; }
			.none { display: none; }

			.flex-none { flex: none; }
			.flex-row { flex-direction: row; }
			.flex-col { flex-direction: column; }

			.justify-center { justify-content: center; }
			.items-center { align-items: center; }

			.text-xs { font-size: 0.75rem; }
			.text-sm { font-size: 0.875rem; }
			.text-xl { font-size: 1.25rem; }
			.text-2xl { font-size: 1.375rem; }
			.text-3xl { font-size: 1.75rem; }
			.text-left { text-align: left; }
			.text-center { text-align: center; }
			.text-right { text-align: right; }

			.font-light { font-weight: 300; }
			.font-bold { font-weight: bold; }

			.gap-1 { gap: 0.25rem; }
			.gap-2 { gap: 0.375rem; }
			.gap-4 { gap: 0.625rem; }
			.gap-7 { gap: 1rem; }
			.gap-8 { gap: 2rem; }

			.fg-2 { color: #7f7faf; }
			.fg-error { color: #df4049; }

			.btn {
				padding: 0.8rem 1rem;
				font-weight: 600;
				background-color: #505080;
				color: #ffffff;
				border-radius: 9999px;
				transition: all ease 300ms;
			}

			.btn:hover {
				cursor: pointer;
				scale: 1.025;
				box-shadow: 1px 2px 2px 1px #eeeefc;
			}

			.input {
				padding: 0.75rem;
				border-radius: 8px;
				border: 1.5px solid #bfcfdf;
			}

			.input::placeholder { color: #7f7faf; }

			.input.expanded {
				height: calc(5rem + 2 * 0.75rem);
			}

			.entry-card {
				padding: 2rem 1rem;
				background-color: #fefefe;
				border-radius: 16px;
				border: 1px solid #eeeefc;
				box-shadow: 1px 2px 4px 1px #e5e5fa;
			}

			.entry-toggle {
				width: 2rem;
				height: 2rem;
				color: #7f7faf;
				border-radius: 50%;
				line-height: 1;
				transition: all ease 300ms;
			}

			.entry-toggle:hover:not(:disabled) {
				cursor: pointer;
				background-color: #eeeefc; 
			}

			.entry-toggle:disabled {
				cursor: not-allowed;
				color: #d2d2ec; 
			}

			.entry-content { transition: opacity ease 200ms; }
			.entry-content.fade { opacity: 0; }

			.container { max-width: 460px; }
			.form-container { border-radius: 24px; }
			.form-inner-container { display: grid; grid-template-rows: 0fr; opacity: 0; }
			.form-inner-container.open { grid-template-rows: 1fr; opacity: 1; }
			.form-inner-container > div { overflow: hidden; }
		</style>
	</head>
	<body class="flex flex-col justify-center items-center">
		<div class="container w-full flex flex-col justify-center items-center gap-8">
			<h1 class="text-3xl font-bold">Hall of Words</h1>

			<?php if (empty($entries)): ?>
				<p>Nothing here.</p>
			<?php else: ?>
				<div class="entry-card w-full flex flex-row items-center gap-4">
					<button disabled class="entry-toggle-prev entry-toggle flex flex-none justify-center items-center text-xl font-bold">
						&lsaquo;
					</button>
					<div class="entry-content w-full flex flex-col items-center gap-7">
						<p class="entry-message text-center text-2xl">-</p>
						<div class="flex flex-col items-center gap-1 text-sm fg-2">
							<p class="entry-name">-</p>
							<p class="entry-created-at">-</p>
						</div>
					</div>
					<button class="entry-toggle-next entry-toggle flex flex-none justify-center items-center text-xl font-bold">
						&rsaquo;
					</button>
				</div>
			<?php endif; ?>

			<form class="form-container w-full flex flex-col gap-4" action="/" method="POST">
				<div class="flex flex-col justify-end gap-2">
					<textarea id="message" class="input" name="message" maxlength="<?= $message_max_length ?>" rows="1" placeholder="Leave a message..."></textarea>
					<p class="message-indicator text-sm text-right none"></p>
				</div>

				<div class="form-inner-container">
					<div class="flex flex-col gap-7">
						<div class="flex flex-col gap-4">
							<div class="flex flex-col gap-2">
								<label for="name" class="text-sm">Name</label>
								<input id="name" class="input" name="name" type="text" maxlength="<?= $name_max_length ?>" placeholder="Enter your name" required />
							</div>
							<div class="flex flex-col gap-2">
								<label for="email" class="text-sm">Email</label>
								<input id="email" class="input" name="email" type="email" maxlength="<?= $email_max_length ?>" placeholder="Enter your email address" required />
							</div>
						</div>
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>" />
						<button class="btn" type="submit">Send message</button>
					</div>
				</div>

				<?php if ($error === "invalid_email"): ?>
					<p class="error-message fg-error">Invalid email address.</p>
				<?php endif; ?>
			</form>

			<footer>
				<p class="text-center text-xs font-light fg-2">
					Website code and design are available under public domain.
					Contents remain the property of their respective owners.
				</p>
			</footer>
		</div>

		<script>
			const entries = <?php echo json_encode($entries) ?>

			if (entries.length > 0) {
				let currentEntryId = 0;

				const togglePrev = document.querySelector(".entry-toggle-prev")
				const toggleNext = document.querySelector(".entry-toggle-next")

				const entryContent = document.querySelector(".entry-content")
				const entryMessage = document.querySelector(".entry-message")
				const entryName = document.querySelector(".entry-name")
				const entryCreatedAt = document.querySelector(".entry-created-at")

				const dateFormatter = new Intl.DateTimeFormat("en-US", {
						weekday: "long",
						day: "numeric",
						month: "long",
						year: "numeric",
						hour: "2-digit",
						minute: "2-digit",
						hour12: true,
				})

				const updateEntry = (id, animate = true) => {
					if (id < 0 || id >= entries.length)
						return

					const update = () => {
						const createdAt = Date.parse(entries[id].created_at)
						const parts = dateFormatter.formatToParts(createdAt)
						const {
							weekday, day, month, year, hour, minute, dayPeriod,
						} = parts.reduce((acc, item) => {
							acc[item.type] = item.value
							return acc
						}, {})
						
						currentEntryId = id
						entryMessage.textContent = entries[id].message
						entryName.textContent = entries[id].name
						entryCreatedAt.textContent = `${weekday}, ${month} ${day}, ${year} at ${hour}:${minute} ${dayPeriod}`

						togglePrev.disabled = currentEntryId - 1 < 0
						toggleNext.disabled = currentEntryId + 1 >= entries.length

						entryContent.classList.remove("fade")
					}

					if (animate) {
						entryContent.classList.add("fade")
						setTimeout(update, 200)
					} else {
						update()
					}
				}

				updateEntry(0, false)

				togglePrev.addEventListener("click", () => {
					updateEntry(currentEntryId - 1)
				})
				toggleNext.addEventListener("click", () => {
					updateEntry(currentEntryId + 1)
				})
			}

			const errorMessage = document.querySelector(".error-message")
			const inputMessage = document.querySelector("#message")
			const inputEmail = document.querySelector("#email")
			const textMessageIndicator = document.querySelector(".message-indicator")
			const containerFormInner = document.querySelector(".form-inner-container")

			textMessageIndicator.update = () => {
				textMessageIndicator.textContent = `${inputMessage.value.length}/${inputMessage.maxLength} characters`
			}

			inputMessage.addEventListener("focus", () => {
				inputMessage.classList.add("expanded")
				containerFormInner.classList.add("open")
				textMessageIndicator.classList.remove("none")
				textMessageIndicator.update()
			})
			inputMessage.addEventListener("blur", () => {
				textMessageIndicator.classList.add("none")
			})
			inputMessage.addEventListener("keydown", (e) => {
				if (e.key === "Enter")
					e.preventDefault()
			})
			inputMessage.addEventListener("input", () => {
				textMessageIndicator.update()
			})

			inputEmail.addEventListener("input", () => {
				errorMessage?.classList.add("none")
			})
		</script>
	</body>
</html>
