<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\RestaurantRegistration;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'username' => 'required|string|max:64',
            'email' => 'required|email|max:320|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $twofactorCode = random_int(100000, 999999);
        $validatedData['two_factor_code'] = $twofactorCode;

        $currentUser = User::create($validatedData);

        Auth::login($currentUser);

        // Send the two-factor code to the user via email message
        Mail::raw("Your two-factor authentication code is: $twofactorCode", function ($message) use ($currentUser) {
            $message->to($currentUser->email)
                    ->subject('Sayfood | Two-Factor Authentication Code');
        });

        return redirect()->route('twofactor.verif');
    }

    public function twoFactorVerification()
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return redirect()->route('show.register');
        }

        $currentUser = Auth::user();

        // Ensure $currentUser is a fresh Eloquent model instance
        if ($currentUser) {
            $currentUser = User::find($currentUser->id);
        }

        // Check if the user has a two-factor code
        if (!$currentUser || !$currentUser->two_factor_code) {
            return redirect()->route('show.register');
        }

        return view('two-factor');
    }

    public function twoFactorSubmit(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'otp' => 'required|integer|digits:6',
        ]);

        # make sure otp is an integer
        $validatedData['otp'] = (int) $validatedData['otp'];

        $currentUser = Auth::user();

        // Ensure $currentUser is a fresh Eloquent model instance
        if ($currentUser) {
            $currentUser = User::find($currentUser->id);
        }

        // Check if the two-factor code matches
        if ($currentUser && $currentUser->two_factor_code === $validatedData['otp']) {
            // Remove the two-factor code from the database
            $currentUser->two_factor_code = null;
            $currentUser->save();

            // Log the user in (if not already)
            Auth::login($currentUser);

            session(['two_factor_verified' => true]);

            return redirect()->route('home')->with('success', 'Two-factor authentication successful.');
        } else {
            throw ValidationException::withMessages([
                'otp' => 'The provided two-factor code is incorrect.',
            ]);
        }
    }

    public function twoFactorResend(Request $request)
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return redirect()->route('show.register');
        }

        $currentUser = Auth::user();

        // Ensure $currentUser is a fresh Eloquent model instance
        if ($currentUser) {
            $currentUser = User::find($currentUser->id);
        }

        // Generate a new two-factor code
        $twofactorCode = random_int(100000, 999999);
        $currentUser->two_factor_code = $twofactorCode;
        $currentUser->save();

        // Send the new two-factor code to the user via email message
        Mail::raw("Your two-factor authentication code is: $twofactorCode", function ($message) use ($currentUser) {
            $message->to($currentUser->email)
                    ->subject('Sayfood | Two-Factor Authentication Code');
        });

        return redirect()->route('twofactor.verif')->with('success', 'A new two-factor authentication code has been sent to your email.');
    }

    public function registerRestaurant(Request $request)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'address' => 'required|string|max:255',
                'email' => 'required|email|max:320|unique:users,email',
            ]);

            RestaurantRegistration::create($validatedData);

            return redirect()->route('home')->with('success', 'Restaurant registration successful. We will contact you soon.');
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }

    public function approveRegistration($id)
    {
        // Find the restaurant registration by ID
        $registration = RestaurantRegistration::findOrFail($id);

        // Create a new user for the restaurant with random username
        $user = User::create([
            'username' => 'restaurant_' . $registration->id,
            'email' => $registration->email,
            'password' => bcrypt('restaurant_' . $registration->id),
            'role' => 'restaurant',
        ]);

        // Create a new restaurant record
        Restaurant::create([
            'user_id' => $user->id,
            'name' => $registration->name,
            'address' => $registration->address,
        ]);


        // Update the status to be 'approved'
        $registration->status = 'approved';
        $registration->save();

        // TO DO: send the restaurant an email with the login credentials

        return;
    }

    public function login(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'username' => 'required|string|max:64',
            'password' => 'required|string|min:8',
        ]);

        if (Auth::attempt($validatedData)) {
            if (Auth::user()->role === 'customer') {
                $request->session()->regenerate();
                return redirect()->route('home');
            }

            Auth::logout();

            // show error
            throw ValidationException::withMessages([
                'credentials' => 'You do not have a customer account.',
            ]);
        }
        else {
            // If auth fails, show error
            throw ValidationException::withMessages([
                'credentials' => 'The provided credentials do not match our records.',
            ]);
        }
    }

    public function loginRestaurant(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'username' => 'required|string|max:64',
            'password' => 'required|string|min:8',
        ]);

        if (Auth::attempt($validatedData)) {
            if (Auth::user()->role === 'restaurant') {
                $request->session()->regenerate();
                return redirect()->route('home.restaurant');
            }

            Auth::logout();

            // show error
            throw ValidationException::withMessages([
                'credentials' => 'You do not have a restaurant account.',
            ]);
        }
        else {
            // If auth fails, show error
            throw ValidationException::withMessages([
                'credentials' => 'The provided credentials do not match our records.',
            ]);
        }
    }

    public function logout(Request $request)
    {
        // Invalidate the user's session
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('show.login');
    }
}
