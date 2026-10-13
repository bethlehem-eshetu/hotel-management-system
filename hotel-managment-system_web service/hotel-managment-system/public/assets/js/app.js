// Utility: show messages nicely
function showMessage(msg, type = "info") {
  const box = document.createElement("div");
  box.className = `alert ${type}`;
  box.innerText = msg;
  document.body.appendChild(box);
  setTimeout(() => box.remove(), 3000);
}

// Handle Login
async function handleLogin(e) {
  e.preventDefault();
  console.log("Login button clicked");

  const form = new FormData(e.target);
  const body = {
    email: form.get("email"),
    password: form.get("password")
  };

  try {
    const res = await fetch("/HOTEL-SYSTEM/public/api/auth/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "include"
    });
    const data = await res.json();
    if (res.ok) {
      showMessage(data.message || "Login successful!", "success");

      // Redirect based on role
      setTimeout(() => {
        const role = data.user?.role;
        if (role === "admin") {
          window.location.href = "/HOTEL-SYSTEM/public/pages/admin.html";
        } 
        else if (role === "manager") {
          window.location.href = "/HOTEL-SYSTEM/public/pages/manager.html";
        } 
        else {
          window.location.href = "/HOTEL-SYSTEM/public/pages/hotels.html"; // customer
        }

      }, 1000);
    } else {
      showMessage(data.error || "Login failed", "error");
    }
  } catch (err) {
    console.error(err);
    showMessage("Network error", "error");
  }
}


// Handle Signup
async function handleSignup(e) {
  e.preventDefault();
  console.log("Signup button clicked");

  const form = new FormData(e.target);
  const body = {
    name: form.get("name"),
    email: form.get("email"),
    password: form.get("password")
  };

  try {
    const res = await fetch("/HOTEL-SYSTEM/public/api/auth/register", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "include"   // <-- include here too
    });
    const data = await res.json();
    if (res.ok) {
      showMessage(data.message || "Signup successful!", "success");
      setTimeout(() => (window.location.href = "login.html"), 1000);
    } else {
      showMessage(data.error || "Signup failed", "error");
    }
  } catch (err) {
    console.error(err);
    showMessage("Network error", "error");
  }
}

// Handle Booking Confirmation
async function handleBooking(e) {
  e.preventDefault();
  console.log("Confirm booking clicked");

  const form = new FormData(e.target);
  const body = {
    room_id: form.get("room_id"),
    check_in_date: form.get("check_in_date"),
    check_out_date: form.get("check_out_date")
  };

  try {
    const res = await fetch("/HOTEL-SYSTEM/public/api/bookings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "include"   // <-- critical for session auth
    });
    const data = await res.json();
    if (res.ok) {
      showMessage("Booking confirmed!", "success");
      setTimeout(() => (window.location.href = "my-bookings.html"), 1000);
    } else {
      showMessage(data.error || "Booking failed", "error");
    }
  } catch (err) {
    console.error(err);
    showMessage("Network error", "error");
  }
}

// Attach listeners when DOM is ready
document.addEventListener("DOMContentLoaded", () => {
  const loginForm = document.getElementById("loginForm");
  if (loginForm) loginForm.addEventListener("submit", handleLogin);

  const signupForm = document.getElementById("signupForm");
  if (signupForm) signupForm.addEventListener("submit", handleSignup);

  const bookingForm = document.getElementById("bookingForm");
  if (bookingForm) bookingForm.addEventListener("submit", handleBooking);
});
