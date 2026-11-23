<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="no-referrer">
  <title>Reset password — Storage</title>
  <link rel="stylesheet" href="theme.css">
  <link rel="stylesheet" href="reset-password.css">
  <script>try{document.documentElement.dataset.theme=localStorage.getItem("storage-theme")==="dark"?"dark":"light"}catch(error){}</script>
</head>
<body>
  <main class="reset-box">
    <div class="brand"><span class="brandmark">S</span><span>Storage</span></div>
    <div class="step-track" aria-label="Password reset steps">
      <div class="step active" id="step-send"><span class="step-number">1</span><span>Send OTP</span></div>
      <div class="step" id="step-verify"><span class="step-number">2</span><span>Verify OTP</span></div>
      <div class="step" id="step-password"><span class="step-number">3</span><span>New password</span></div>
    </div>

    <section id="send-panel">
      <div class="otp-icon" aria-hidden="true">✉</div>
      <h1>Reset your password</h1>
      <p class="reset-intro">Enter your account email and we’ll send you a six-digit verification code.</p>
      <form id="send-form">
        <div class="field">
          <label for="reset-email">Email address</label>
          <input id="reset-email" type="email" maxlength="190" autocomplete="email" required>
          <small>The code goes to the email saved on your Storage account. Check your spam folder too.</small>
        </div>
        <button class="btn btn-primary" type="submit">Send OTP code</button>
      </form>
    </section>

    <section id="verify-panel" class="hidden">
      <div class="otp-icon" aria-hidden="true">✉</div>
      <h1>Check your email</h1>
      <p class="reset-intro" id="verify-intro">Enter the six-digit code we sent you. It expires in 10 minutes.</p>
      <form id="verify-form">
        <div class="field">
          <label for="reset-code">Verification code</label>
          <input id="reset-code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="000000" aria-describedby="code-hint" required>
          <small id="code-hint">You have up to five attempts.</small>
        </div>
        <button class="btn btn-primary" type="submit">Verify code</button>
      </form>
    </section>

    <section id="password-panel" class="hidden">
      <div class="otp-icon" aria-hidden="true">✓</div>
      <h1>Code verified</h1>
      <p class="reset-intro">Now choose a new password for your account.</p>
      <form id="password-form">
        <div class="field">
          <label for="new-password">New password</label>
          <input id="new-password" type="password" minlength="8" maxlength="4096" autocomplete="new-password" placeholder="At least 8 characters" required>
        </div>
        <div class="field">
          <label for="confirm-password">Confirm new password</label>
          <input id="confirm-password" type="password" minlength="8" maxlength="4096" autocomplete="new-password" placeholder="Enter it again" required>
        </div>
        <button class="btn btn-primary" type="submit">Save new password</button>
      </form>
    </section>

    <div id="reset-message" class="reset-message hidden" role="status" aria-live="polite"></div>
    <a class="back-link" href="index.html">← Back to sign in</a>
  </main>
  <script>
    (() => {
      const sendForm = document.getElementById("send-form");
      const verifyForm = document.getElementById("verify-form");
      const passwordForm = document.getElementById("password-form");
      const message = document.getElementById("reset-message");
      const emailInput = document.getElementById("reset-email");
      let resetEmail = "";

      const showMessage = (text, error) => {
        message.textContent = text;
        message.className = "reset-message " + (error ? "error" : "success");
      };
      const setStep = step => {
        const steps = ["send","verify","password"];
        if (step === "complete") {
          steps.forEach(name => {
            document.getElementById(name + "-panel").classList.add("hidden");
            document.getElementById("step-" + name).classList.remove("active");
            document.getElementById("step-" + name).classList.add("done");
          });
          message.classList.add("hidden");
          return;
        }
        steps.forEach(name => {
          document.getElementById(name + "-panel").classList.toggle("hidden",name !== step);
          document.getElementById("step-" + name).classList.toggle("active",name === step);
          document.getElementById("step-" + name).classList.toggle("done",
            name !== step && steps.indexOf(name) < steps.indexOf(step));
        });
        message.classList.add("hidden");
      };
      const sendRequest = async (action, payload, csrf) => {
        const response = await fetch("api.php?action=" + action, {
          method:"POST",
          credentials:"same-origin",
          headers:{"Content-Type":"application/json","X-CSRF-Token":csrf},
          body:JSON.stringify(payload)
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Could not complete this step. Please try again.");
        return result;
      };
      const getCsrf = async () => {
        const response = await fetch("api.php?action=csrf", {credentials:"same-origin",cache:"no-store"});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Could not start the reset. Please try again.");
        return result.csrf;
      };

      sendForm.addEventListener("submit", async event => {
        event.preventDefault();
        const button = sendForm.querySelector("button[type=submit]");
        button.disabled = true;
        try {
          const email = emailInput.value.trim();
          const csrf = await getCsrf();
          const result = await sendRequest("forgot-password",{email},csrf);
          resetEmail = email;
          sessionStorage.setItem("storage-reset-email",email);
          document.getElementById("verify-intro").textContent = result.message + " Enter it below. The code expires in 10 minutes.";
          document.getElementById("step-password").classList.remove("done");
          setStep("verify");
          document.getElementById("reset-code").focus();
        } catch (error) {
          showMessage(error.message || "Could not send a reset code. Please try again.",true);
        } finally {
          button.disabled = false;
        }
      });

      verifyForm.addEventListener("submit", async event => {
        event.preventDefault();
        const button = verifyForm.querySelector("button[type=submit]");
        button.disabled = true;
        try {
          const csrf = await getCsrf();
          await sendRequest("verify-reset-code", {
            email:resetEmail,
            code:document.getElementById("reset-code").value.trim()
          },csrf);
          sessionStorage.removeItem("storage-reset-email");
          setStep("password");
          document.getElementById("new-password").focus();
        } catch (error) {
          showMessage(error.message || "Could not verify the code. Please try again.",true);
        } finally {
          button.disabled = false;
        }
      });

      passwordForm.addEventListener("submit", async event => {
        event.preventDefault();
        const password = document.getElementById("new-password").value;
        if (password !== document.getElementById("confirm-password").value) {
          showMessage("The passwords do not match.",true);
          return;
        }
        const button = passwordForm.querySelector("button[type=submit]");
        button.disabled = true;
        try {
          const csrf = await getCsrf();
          const result = await sendRequest("reset-password",{password},csrf);
          setStep("complete");
          passwordForm.classList.add("hidden");
          showMessage(result.message,false);
          sessionStorage.removeItem("storage-reset-email");
        } catch (error) {
          showMessage(error.message || "Could not reset your password. Please try again.",true);
        } finally {
          button.disabled = false;
        }
      });

      const savedEmail = sessionStorage.getItem("storage-reset-email");
      if (savedEmail) {
        resetEmail = savedEmail;
        emailInput.value = savedEmail;
        document.getElementById("verify-intro").textContent = "Enter the six-digit code sent to " + savedEmail + ". It expires in 10 minutes.";
        setStep("verify");
      }
    })();
  </script>
</body>
</html>
