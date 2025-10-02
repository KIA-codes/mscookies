#include <bits/stdc++.h>
using namespace std;

int main() {
    ios::sync_with_stdio(false);
    cin.tie(nullptr);

    cout << "Roven C++ File - Simple REPL Calculator (letters supported)\n";
    cout << "Type expressions like: a=5, b=3 then a*b+2. Ctrl+C to exit.\n\n";

    unordered_map<string, long double> variables;

    auto isIdentifier = [](const string &s) -> bool {
        if (s.empty() || (!isalpha(s[0]) && s[0] != '_')) return false;
        for (char c : s) {
            if (!isalnum(c) && c != '_') return false;
        }
        return true;
    };

    // Very small and naive evaluator: supports + - * / ^ and parentheses
    function<long double(const string&, size_t&)> parseExpr;
    function<long double(const string&, size_t&)> parseTerm;
    function<long double(const string&, size_t&)> parseFactor;

    auto skipSpaces = [](const string &s, size_t &i) {
        while (i < s.size() && isspace(static_cast<unsigned char>(s[i]))) i++;
    };

    parseFactor = [&](const string &s, size_t &i) -> long double {
        skipSpaces(s, i);
        if (i < s.size() && (s[i] == '+' || s[i] == '-')) {
            char sign = s[i++];
            long double val = parseFactor(s, i);
            return sign == '-' ? -val : val;
        }
        if (i < s.size() && s[i] == '(') {
            i++;
            long double val = parseExpr(s, i);
            skipSpaces(s, i);
            if (i >= s.size() || s[i] != ')') throw runtime_error("Missing )");
            i++;
            return val;
        }
        // number
        if (i < s.size() && (isdigit(static_cast<unsigned char>(s[i])) || s[i] == '.')) {
            size_t j = i;
            while (j < s.size() && (isdigit(static_cast<unsigned char>(s[j])) || s[j] == '.')) j++;
            long double val = stold(s.substr(i, j - i));
            i = j;
            return val;
        }
        // identifier
        if (i < s.size() && (isalpha(static_cast<unsigned char>(s[i])) || s[i] == '_')) {
            size_t j = i + 1;
            while (j < s.size() && (isalnum(static_cast<unsigned char>(s[j])) || s[j] == '_')) j++;
            string id = s.substr(i, j - i);
            i = j;
            auto it = variables.find(id);
            if (it == variables.end()) throw runtime_error("Unknown variable: " + id);
            return it->second;
        }
        throw runtime_error("Unexpected token");
    };

    parseTerm = [&](const string &s, size_t &i) -> long double {
        long double val = parseFactor(s, i);
        while (true) {
            skipSpaces(s, i);
            if (i < s.size() && (s[i] == '*' || s[i] == '/' )) {
                char op = s[i++];
                long double rhs = parseFactor(s, i);
                if (op == '*') val *= rhs; else val /= rhs;
            } else {
                break;
            }
        }
        return val;
    };

    parseExpr = [&](const string &s, size_t &i) -> long double {
        long double val = parseTerm(s, i);
        while (true) {
            skipSpaces(s, i);
            if (i < s.size() && (s[i] == '+' || s[i] == '-')) {
                char op = s[i++];
                long double rhs = parseTerm(s, i);
                if (op == '+') val += rhs; else val -= rhs;
            } else if (i < s.size() && s[i] == '^') {
                i++;
                long double rhs = parseFactor(s, i);
                val = pow(val, rhs);
            } else {
                break;
            }
        }
        return val;
    };

    string line;
    while (true) {
        cout << ">> ";
        if (!getline(cin, line)) break;
        try {
            // support multiple comma-separated assignments, return last value
            // e.g., a=5, b=3, a*b
            string s = line;
            size_t pos = 0;
            long double last = 0;
            bool any = false;

            auto evalOne = [&](const string &chunk) {
                size_t i = 0;
                // assignment?
                size_t eq = chunk.find('=');
                if (eq != string::npos) {
                    string lhs = chunk.substr(0, eq);
                    string rhs = chunk.substr(eq + 1);
                    // trim
                    auto trim = [](string &t){
                        size_t a = t.find_first_not_of(" \t\n\r");
                        size_t b = t.find_last_not_of(" \t\n\r");
                        if (a == string::npos) { t.clear(); return; }
                        t = t.substr(a, b - a + 1);
                    };
                    trim(lhs); trim(rhs);
                    if (!isIdentifier(lhs)) throw runtime_error("Invalid identifier");
                    i = 0;
                    long double value = parseExpr(rhs, i);
                    variables[lhs] = value;
                    return value;
                } else {
                    i = 0;
                    return parseExpr(chunk, i);
                }
            };

            while (pos < s.size()) {
                size_t next = s.find(',', pos);
                string part = s.substr(pos, (next == string::npos) ? string::npos : next - pos);
                // trim
                auto trim2 = [](string &t){
                    size_t a = t.find_first_not_of(" \t\n\r");
                    size_t b = t.find_last_not_of(" \t\n\r");
                    if (a == string::npos) { t.clear(); return; }
                    t = t.substr(a, b - a + 1);
                };
                trim2(part);
                if (!part.empty()) {
                    last = evalOne(part);
                    any = true;
                }
                if (next == string::npos) break;
                pos = next + 1;
            }

            if (any) cout << last << "\n";
        } catch (const exception &ex) {
            cout << "Error: " << ex.what() << "\n";
        }
    }

    return 0;
}



