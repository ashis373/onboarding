import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';

export interface AuthUser {
  uid: number;
  username: string;
  emp_code: string;
  firstname: string;
  lastname: string;
  gender: string;
  profile_img: string;
  birthdate: string;
  ofc_mail: string;
  department?: string;
  ofc_location?: string;
  designation?: string;
  designation_name?: string;
  joiningdate?: string;
  qualification?: string;
  personal_email?: string;
  phone?: string;
  address?: string;
  emp_type?: string;
  status?: string;
  session_key?: string;
  user_role?: string;
  special_privilege?: string;
  account_manager?: string;
  last_active_time?: string;
  reporting_to?: string;
  presence_status?: string;
  presence_status_message?: string;
  profile_img_url?: string;
  department_name?: string;
}

export function useAuth() {
  const { data, isLoading } = useQuery({
    queryKey: ['auth_user'],
    queryFn: async () => {
      try {
        const res = await apiGet<{ authenticated: boolean; user?: AuthUser }>(`/auth/status.php`);
        return res;
      } catch (_e) {
        return { authenticated: false, user: undefined };
      }
    },
    staleTime: 5 * 60 * 1000, // 5 minutes
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    retry: 1,
  });

  return {
    user: data?.authenticated ? (data.user || null) : null,
    loading: isLoading,
  };
}


